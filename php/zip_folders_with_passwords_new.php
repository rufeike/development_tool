<?php
/**
 * 按文件夹批量打包 ZIP。
 * - `--base` 目录下的每个目标文件夹都会生成一个独立密码。
 * - 每个 ZIP 的输出路径与密码会记录到一个 JSON 文件中，方便后续查询与交付。
 *
 * 使用方法（CLI）：
 *   php zip_folders_with_passwords.php --base=files --depth=1 --out=zips --map=zip_passwords.json
 *   php zip_folders_with_passwords.php --base=files --depth=1 --out=zips --map=zip_passwords.json --bundle=1
 *
 * 参数说明：
 *   --base=PATH            需要扫描的根目录 (default: ./files)
 *   --depth=N              需要打包的文件夹层级深度（1 = 只打包 base 下的一级子目录） (default: 1)
 *   --out=PATH             ZIP 输出目录 (default: ./zips)
 *   --map=PATH             记录密码/输出信息的 JSON 文件路径 (default: ./zip_passwords.json)
 *   --algo=auto|ziparchive|7z|zip
 *                          打包后端（auto 会优先用 ZipArchive，不行则尝试 7z，最后尝试系统 zip 命令） (default: auto)
 *   --overwrite=0|1        是否覆盖已存在的 zip 文件 (default: 0)
 *   --print-passwords=0|1  是否在控制台输出密码（不建议开启，避免泄露） (default: 0)
 *   --bundle=0|1           是否把整个输出目录再打成一个“总包”zip (default: 0)
 *   --bundle-name=NAME     总包 zip 文件名 (default: auto-generate)
 *   --bundle-password=PWD  总包 zip 密码 (default: auto-generate)
 *   --bundle-include-map=0|1
 *                          是否把 JSON 映射文件也打进总包 zip 里 (default: 0)
 *   --txt=PATH             导出“可打印”的密码 TXT 文件路径 (default: disabled)
 *   --csv=PATH             导出密码 CSV 文件路径（方便 Excel 打开/打印） (default: disabled)
 *   --csv-bom=0|1          CSV 是否写入 UTF-8 BOM（Excel 更友好） (default: 1)
 *   --nest=0|1             是否按层级“子包包含进父包”（zip-in-zip）。
 *                          - 0: 保持原行为：只打包 depth 指定那一层，每个目录独立一个 zip
 *                          - 1: depth 作为“最大深度”，会从最深层开始打包，并把子层 zip 文件加入父层 zip 中
 *                          注意：nest=1 需要 ZipArchive（用于读取/构造层级关系）。
 *   --nest-parent-encrypt=0|1
 *                          nest=1 时，父层 ZIP（包含子 ZIP 的那一层）是否加密。
 *                          - 1: 会用 7z 生成父包并加密（推荐/默认；可避开 ZipArchive 在 Windows 下的限制）
 *                          - 0: 父包不加密（仍可依赖子包密码）
 *                          default: 1
 */

declare(strict_types=1);

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

function is_cli(): bool {
    return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
}

function cli_out(string $msg): void {
    if (is_cli()) {
        fwrite(STDOUT, $msg . PHP_EOL);
    } else {
        echo htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "<br>\n";
    }
}

function cli_err(string $msg): void {
    if (is_cli()) {
        fwrite(STDERR, $msg . PHP_EOL);
    } else {
        echo '<span style="color:red">' . htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</span><br>\n";
    }
}

function ensure_dir(string $dir): void {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create directory: {$dir}");
        }
    }
}

function ensure_empty_dir(string $dir): void {
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $info) {
            /** @var SplFileInfo $info */
            if ($info->isDir()) {
                @rmdir($info->getPathname());
            } else {
                @unlink($info->getPathname());
            }
        }
        @rmdir($dir);
    }
    ensure_dir($dir);
}

function write_password_txt(string $txtPath, array $map, bool $includeBundle = true): void {
    $dir = dirname($txtPath);
    if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
        ensure_dir($dir);
    }

    $lines = [];
    $lines[] = 'GeneratedAt: ' . ($map['generatedAt'] ?? date('c'));
    $lines[] = 'BaseDir: ' . ($map['baseDir'] ?? '');
    $lines[] = 'OutDir: ' . ($map['outDir'] ?? '');
    $lines[] = '';
    $lines[] = '=== Folder ZIP Passwords ===';

    $entries = $map['entries'] ?? [];
    if (is_array($entries)) {
        foreach ($entries as $rel => $detail) {
            if (!is_array($detail)) {
                continue;
            }
            $folder = (string)($detail['folder'] ?? (is_string($rel) ? $rel : ''));
            $zip = (string)($detail['zip'] ?? '');
            $pwd = (string)($detail['password'] ?? '');
            $status = (string)($detail['status'] ?? '');
            $encrypted = array_key_exists('encrypted', $detail) ? (string)((bool)$detail['encrypted'] ? 'yes' : 'no') : '';

            $lines[] = 'Folder: ' . $folder;
            $lines[] = 'Zip: ' . $zip;
            $lines[] = 'Password: ' . $pwd;
            if ($status !== '') {
                $lines[] = 'Status: ' . $status;
            }
            if ($encrypted !== '') {
                $lines[] = 'Encrypted: ' . $encrypted;
            }
            $lines[] = str_repeat('-', 40);
        }
    }

    if ($includeBundle && isset($map['bundle']) && is_array($map['bundle']) && !empty($map['bundle'])) {
        $b = $map['bundle'];
        $lines[] = '';
        $lines[] = '=== Bundle ZIP Password ===';
        $lines[] = 'Zip: ' . (string)($b['zip'] ?? '');
        $lines[] = 'Password: ' . (string)($b['password'] ?? '');
        $lines[] = 'Status: ' . (string)($b['status'] ?? '');
        $lines[] = 'Encrypted: ' . (string)((bool)($b['encrypted'] ?? false) ? 'yes' : 'no');
    }

    $content = implode(PHP_EOL, $lines) . PHP_EOL;
    if (file_put_contents($txtPath, $content) === false) {
        throw new RuntimeException('Failed to write txt file: ' . $txtPath);
    }
}

function write_password_csv(string $csvPath, array $map, bool $includeBundle = true, bool $writeBom = true): void {
    $dir = dirname($csvPath);
    if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
        ensure_dir($dir);
    }

    $fp = fopen($csvPath, 'wb');
    if ($fp === false) {
        throw new RuntimeException('Failed to open csv file for writing: ' . $csvPath);
    }

    if ($writeBom) {
        fwrite($fp, "\xEF\xBB\xBF");
    }

    // Columns: type, folder, zip, password, status, encrypted, createdAt
    // Provide escape explicitly to avoid PHP 8.4 deprecation warnings.
    $csvSeparator = ',';
    $csvEnclosure = '"';
    $csvEscape = '\\';
    fputcsv($fp, ['type', 'folder', 'zip', 'password', 'status', 'encrypted', 'createdAt'], $csvSeparator, $csvEnclosure, $csvEscape);

    $entries = $map['entries'] ?? [];
    if (is_array($entries)) {
        foreach ($entries as $rel => $detail) {
            if (!is_array($detail)) {
                continue;
            }
            $folder = (string)($detail['folder'] ?? (is_string($rel) ? $rel : ''));
            $zip = (string)($detail['zip'] ?? '');
            $pwd = (string)($detail['password'] ?? '');
            $status = (string)($detail['status'] ?? '');
            $encrypted = array_key_exists('encrypted', $detail) ? ((bool)$detail['encrypted'] ? 'yes' : 'no') : '';
            $createdAt = (string)($detail['createdAt'] ?? '');
            fputcsv($fp, ['folder', $folder, $zip, $pwd, $status, $encrypted, $createdAt], $csvSeparator, $csvEnclosure, $csvEscape);
        }
    }

    if ($includeBundle && isset($map['bundle']) && is_array($map['bundle']) && !empty($map['bundle'])) {
        $b = $map['bundle'];
        $zip = (string)($b['zip'] ?? '');
        $pwd = (string)($b['password'] ?? '');
        $status = (string)($b['status'] ?? '');
        $encrypted = (bool)($b['encrypted'] ?? false) ? 'yes' : 'no';
        $createdAt = (string)($b['createdAt'] ?? '');
        fputcsv($fp, ['bundle', '', $zip, $pwd, $status, $encrypted, $createdAt], $csvSeparator, $csvEnclosure, $csvEscape);
    }

    fclose($fp);
}

function normalize_rel_path(string $path): string {
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path);
    return trim((string)$path, '/');
}

function safe_filename(string $name): string {
    // Windows-invalid: < > : " / \ | ? *
    $name = preg_replace('#[<>:"/\\\\|\?\*]+#', '_', $name);
    $name = trim((string)$name);
    $name = rtrim($name, ". ");
    if ($name === '' || $name === '.' || $name === '..') {
        $name = 'folder';
    }
    return $name;
}

function rand_password(int $length = 18): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*()-_=+';
    $max = strlen($alphabet) - 1;
    $bytes = random_bytes($length);
    $pwd = '';
    for ($i = 0; $i < $length; $i++) {
        $pwd .= $alphabet[ord($bytes[$i]) % ($max + 1)];
    }
    return $pwd;
}

function list_dirs_at_depth(string $baseDir, int $depth, ?string $excludePrefix = null): array {
    $baseDirReal = realpath($baseDir);
    if ($baseDirReal === false) {
        throw new RuntimeException("Base directory not found: {$baseDir}");
    }

    $excludeReal = null;
    if ($excludePrefix !== null) {
        $excludeReal = realpath($excludePrefix);
    }

    $targetDepth = max(0, $depth - 1);
    $dirs = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDirReal, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    /** @var SplFileInfo $info */
    foreach ($iterator as $info) {
        if (!$info->isDir()) {
            continue;
        }
        if ($iterator->getDepth() !== $targetDepth) {
            continue;
        }

        $dirPath = $info->getPathname();
        $dirReal = realpath($dirPath);
        if ($dirReal === false) {
            continue;
        }
        if ($excludeReal !== null) {
            // Skip output directory if it is inside base.
            if (stripos($dirReal, $excludeReal) === 0) {
                continue;
            }
        }

        $dirs[] = $dirReal;
    }

    sort($dirs);
    return $dirs;
}

function list_dirs_up_to_depth(string $baseDir, int $maxDepth, ?string $excludePrefix = null): array {
    $baseDirReal = realpath($baseDir);
    if ($baseDirReal === false) {
        throw new RuntimeException("Base directory not found: {$baseDir}");
    }

    $excludeReal = null;
    if ($excludePrefix !== null) {
        $excludeReal = realpath($excludePrefix);
    }

    $maxIteratorDepth = max(0, $maxDepth - 1);
    $dirs = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDirReal, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    /** @var SplFileInfo $info */
    foreach ($iterator as $info) {
        if (!$info->isDir()) {
            continue;
        }
        if ($iterator->getDepth() > $maxIteratorDepth) {
            continue;
        }

        $dirPath = $info->getPathname();
        $dirReal = realpath($dirPath);
        if ($dirReal === false) {
            continue;
        }
        if ($excludeReal !== null) {
            // Skip output directory if it is inside base.
            if (stripos($dirReal, $excludeReal) === 0) {
                continue;
            }
        }
        $dirs[] = $dirReal;
    }

    sort($dirs);
    return $dirs;
}

function rel_depth(string $relNorm): int {
    $relNorm = normalize_rel_path($relNorm);
    if ($relNorm === '') {
        return 0;
    }
    return substr_count($relNorm, '/') + 1;
}

function zip_with_ziparchive(string $baseDir, string $folderDir, string $zipPath, string $zipRoot, string $password, bool $overwrite, array &$detail): bool {
    if (!class_exists('ZipArchive')) {
        $detail['error'] = 'ZipArchive not available';
        return false;
    }

    $zip = new ZipArchive();
    $flags = ZipArchive::CREATE;
    if ($overwrite) {
        $flags |= ZipArchive::OVERWRITE;
    } else {
        if (file_exists($zipPath)) {
            $detail['error'] = 'Zip already exists and overwrite=0';
            return false;
        }
    }

    $openRes = $zip->open($zipPath, $flags);
    if ($openRes !== true) {
        $detail['error'] = 'Failed to open zip (code=' . (string)$openRes . ')';
        return false;
    }

    $zipRoot = normalize_rel_path($zipRoot);
    if ($zipRoot !== '') {
        $zipRoot .= '/';
    }

    $supportsEncryption = method_exists($zip, 'setEncryptionName')
        && method_exists($zip, 'setPassword')
        && (defined('ZipArchive::EM_AES_256') || defined('ZipArchive::EM_TRAD_PKWARE'));

    $encrypted = false;
    if ($supportsEncryption) {
        $zip->setPassword($password);
    }

    $folderReal = realpath($folderDir);
    if ($folderReal === false) {
        $zip->close();
        $detail['error'] = 'Folder not found';
        return false;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folderReal, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    /** @var SplFileInfo $info */
    foreach ($it as $info) {
        $fullPath = $info->getPathname();
        $relInFolder = substr($fullPath, strlen($folderReal) + 1);
        $relInFolder = str_replace('\\', '/', $relInFolder);
        $localName = $zipRoot . $relInFolder;

        if ($info->isDir()) {
            $zip->addEmptyDir(rtrim($localName, '/'));
            continue;
        }

        if (!$zip->addFile($fullPath, $localName)) {
            $zip->close();
            $detail['error'] = 'Failed to add file: ' . $fullPath;
            return false;
        }

        if ($supportsEncryption) {
            $method = defined('ZipArchive::EM_AES_256') ? ZipArchive::EM_AES_256 : ZipArchive::EM_TRAD_PKWARE;
            $ok = $zip->setEncryptionName($localName, $method);
            if ($ok) {
                $encrypted = true;
            }
        }
    }

    $zip->close();
    $detail['encrypted'] = $encrypted;
    if ($supportsEncryption && !$encrypted) {
        $detail['warning'] = 'ZipArchive encryption API exists but no file was encrypted (libzip might lack crypto support)';
    } elseif (!$supportsEncryption) {
        $detail['warning'] = 'ZipArchive encryption not supported in this PHP build; zip is NOT encrypted';
    }

    return true;
}

function zip_with_ziparchive_nested_children(string $folderDir, string $zipPath, string $zipRoot, string $password, bool $overwrite, array $childZips, array &$detail): bool {
    if (!class_exists('ZipArchive')) {
        $detail['error'] = 'ZipArchive not available';
        return false;
    }

    $zip = new ZipArchive();
    $flags = ZipArchive::CREATE;
    if ($overwrite) {
        $flags |= ZipArchive::OVERWRITE;
    } else {
        if (file_exists($zipPath)) {
            $detail['error'] = 'Zip already exists and overwrite=0';
            return false;
        }
    }

    $openRes = $zip->open($zipPath, $flags);
    if ($openRes !== true) {
        $detail['error'] = 'Failed to open zip (code=' . (string)$openRes . ')';
        return false;
    }

    $zipRoot = normalize_rel_path($zipRoot);
    if ($zipRoot !== '') {
        $zipRoot .= '/';
    }

    // IMPORTANT: Do not enable ZipArchive encryption in this function.
    // On some Windows ZipArchive/libzip builds, having ANY encrypted entry while also embedding
    // multiple child zip files can cause the output to silently keep only the first embedded zip.
    // We keep parent zip unencrypted; child zips remain password-protected themselves.
    $encrypted = false;

    $folderReal = realpath($folderDir);
    if ($folderReal === false) {
        $zip->close();
        $detail['error'] = 'Folder not found';
        return false;
    }

    // 1) Add immediate files in this folder (do NOT recurse subfolders).
    $it = new DirectoryIterator($folderReal);
    /** @var DirectoryIterator $info */
    foreach ($it as $info) {
        if ($info->isDot()) {
            continue;
        }
        if (!$info->isFile()) {
            continue;
        }

        $fullPath = $info->getPathname();
        $localName = $zipRoot . $info->getFilename();

        if (!$zip->addFile($fullPath, $localName)) {
            $zip->close();
            $detail['error'] = 'Failed to add file: ' . $fullPath;
            return false;
        }

        // parent zip remains unencrypted in nested mode
    }

    // 2) Add child zips into this zip.
    // NOTE (Windows/libzip quirk): On some PHP ZipArchive/libzip builds, encrypting embedded *.zip
    // entries (setEncryptionName on the embedded child zip file) can cause the archive to silently
    // keep only the first embedded zip. We therefore do NOT encrypt the embedded child zip entries;
    // the child zip itself is already password-protected.
    // Additionally, adding multiple *.zip source files via addFile() may misbehave; work around by
    // copying the child zip into a temp file with a non-.zip extension, then addFile() from that temp.
    $tmpFiles = [];
    foreach ($childZips as $childFolderName => $childZipPath) {
        if (!is_string($childFolderName) || $childFolderName === '' || !is_string($childZipPath) || $childZipPath === '') {
            continue;
        }
        if (!is_file($childZipPath)) {
            $zip->close();
            $detail['error'] = 'Child zip not found: ' . $childZipPath;
            return false;
        }

        $entryName = safe_filename($childFolderName);
        if (!str_ends_with(strtolower($entryName), '.zip')) {
            $entryName .= '.zip';
        }
        $localName = $zipRoot . $entryName;

        $tmpDir = dirname($zipPath);
        $tmpDirReal = realpath($tmpDir);
        if ($tmpDirReal === false) {
            $zip->close();
            $detail['error'] = 'Temp directory not found: ' . $tmpDir;
            return false;
        }

        try {
            $tmpName = 'zipchild_' . bin2hex(random_bytes(8)) . '.dat';
        } catch (Throwable $e) {
            $zip->close();
            $detail['error'] = 'Failed to generate temp name for child zip';
            return false;
        }
        $tmp = rtrim($tmpDirReal, "\\/ ") . DS . $tmpName;

        if (!copy($childZipPath, $tmp)) {
            @unlink($tmp);
            $zip->close();
            $detail['error'] = 'Failed to copy child zip to temp: ' . $childZipPath;
            return false;
        }
        $tmpFiles[] = $tmp;

        if (!$zip->addFile($tmp, $localName)) {
            $zip->close();
            foreach ($tmpFiles as $t) {
                @unlink($t);
            }
            $detail['error'] = 'Failed to add child zip: ' . $childZipPath;
            return false;
        }
    }

    $zip->close();
    foreach ($tmpFiles as $t) {
        @unlink($t);
    }
    $detail['encrypted'] = false;
    $detail['warning'] = 'Nested parent zip is NOT encrypted due to a ZipArchive/libzip limitation when embedding multiple child zip files; rely on child zip passwords for protection.';

    return true;
}

function zip_parent_with_7z_from_staging(string $folderDir, string $zipPath, string $zipRoot, string $password, bool $overwrite, array $childZips, array &$detail): bool {
    // Build a staging directory that mirrors the desired archive layout, then let 7z/zip create an encrypted zip.
    $has7z = detect_7z() !== null;
    $hasZip = detect_zip_cmd() !== null;
    if (!$has7z && !$hasZip) {
        $detail['error'] = 'No zip tool found for encrypted parent zips (install 7-Zip or zip command)';
        return false;
    }

    $folderReal = realpath($folderDir);
    if ($folderReal === false) {
        $detail['error'] = 'Folder not found';
        return false;
    }

    $zipRoot = normalize_rel_path($zipRoot);
    if ($zipRoot === '') {
        $detail['error'] = 'Invalid zipRoot';
        return false;
    }

    $stageBase = rtrim(sys_get_temp_dir(), "\\/ ") . DS . 'zip_stage_' . bin2hex(random_bytes(8));
    try {
        ensure_empty_dir($stageBase);

        $stageRoot = $stageBase . DS . str_replace('/', DS, $zipRoot);
        ensure_dir($stageRoot);

        // 1) Copy immediate files in this folder.
        $it = new DirectoryIterator($folderReal);
        foreach ($it as $info) {
            /** @var DirectoryIterator $info */
            if ($info->isDot() || !$info->isFile()) {
                continue;
            }
            $src = $info->getPathname();
            $dst = $stageRoot . DS . $info->getFilename();
            if (!copy($src, $dst)) {
                throw new RuntimeException('Failed to stage file: ' . $src);
            }
        }

        // 2) Copy child zip files into staged root.
        foreach ($childZips as $childFolderName => $childZipPath) {
            if (!is_string($childFolderName) || $childFolderName === '' || !is_string($childZipPath) || $childZipPath === '') {
                continue;
            }
            if (!is_file($childZipPath)) {
                throw new RuntimeException('Child zip not found: ' . $childZipPath);
            }

            $entryName = safe_filename($childFolderName);
            if (!str_ends_with(strtolower($entryName), '.zip')) {
                $entryName .= '.zip';
            }
            $dst = $stageRoot . DS . $entryName;
            if (!copy($childZipPath, $dst)) {
                throw new RuntimeException('Failed to stage child zip: ' . $childZipPath);
            }
        }

        // 3) Zip the staged zipRoot directory (prefer 7z AES, fallback to zip ZipCrypto).
        $ok = false;
        if ($has7z) {
            $detail['method'] = '7z';
            $ok = zip_with_7z($stageBase, $zipRoot, $zipPath, $password, $overwrite, $detail);
        }
        if (!$ok && $hasZip) {
            $detail['method'] = 'zipcmd';
            $ok = zip_with_zipcmd($stageBase, $zipRoot, $zipPath, $password, $overwrite, $detail);
        }
        if (!$ok) {
            return false;
        }
        $detail['encrypted'] = true;
        return true;
    } catch (Throwable $e) {
        $detail['error'] = $e->getMessage();
        return false;
    } finally {
        // best-effort cleanup
        if (is_dir($stageBase)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($stageBase, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $info) {
                /** @var SplFileInfo $info */
                if ($info->isDir()) {
                    @rmdir($info->getPathname());
                } else {
                    @unlink($info->getPathname());
                }
            }
            @rmdir($stageBase);
        }
    }
}

function run_command(string $command, string $cwd, array &$outLines, int &$exitCode): void {
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open($command, $descriptors, $pipes, $cwd);
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to start process');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($proc);

    $outLines = [];
    foreach ([$stdout, $stderr] as $blob) {
        $blob = trim((string)$blob);
        if ($blob === '') {
            continue;
        }
        foreach (preg_split("/\r\n|\n|\r/", $blob) as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $outLines[] = $line;
            }
        }
    }
}

function quote_exe(string $exe): string {
    // Quote executable path for Windows (spaces) and general safety.
    return '"' . str_replace('"', '""', $exe) . '"';
}

function detect_zip_cmd(): ?string {
    $isWin = stripos(PHP_OS, 'WIN') === 0;
    $cmd = 'zip';
    $which = is_cli() ? ($isWin ? "where {$cmd}" : "command -v {$cmd}") : null;
    if ($which === null) {
        return null;
    }

    $out = [];
    $code = 1;
    try {
        run_command($which, getcwd(), $out, $code);
    } catch (Throwable $e) {
        return null;
    }

    if ($code === 0 && !empty($out)) {
        $first = trim((string)$out[0]);
        return $first !== '' ? $first : $cmd;
    }
    return null;
}

function detect_7z(): ?string {
    $isWin = stripos(PHP_OS, 'WIN') === 0;

    // 1) Try PATH (where/command -v)
    foreach (['7z', '7za', '7zr'] as $cmd) {
        $which = is_cli() ? ($isWin ? "where {$cmd}" : "command -v {$cmd}") : null;
        if ($which === null) {
            continue;
        }
        $out = [];
        $code = 1;
        try {
            run_command($which, getcwd(), $out, $code);
        } catch (Throwable $e) {
            continue;
        }
        if ($code === 0 && !empty($out)) {
            $first = trim((string)$out[0]);
            if ($first !== '') {
                return $first;
            }
            return $cmd;
        }
    }

    // 2) Try common install locations on Windows
    if ($isWin) {
        $candidates = [
            'C:\\Program Files\\7-Zip\\7z.exe',
            'C:\\Program Files (x86)\\7-Zip\\7z.exe',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
    }

    return null;
}

function zip_with_7z(string $baseDir, string $relFolder, string $zipPath, string $password, bool $overwrite, array &$detail): bool {
    $sevenZip = detect_7z();
    if ($sevenZip === null) {
        $detail['error'] = '7z not found (install 7-Zip; script checks PATH and common install locations)';
        return false;
    }

    if (!$overwrite && file_exists($zipPath)) {
        $detail['error'] = 'Zip already exists and overwrite=0';
        return false;
    }

    // Workdir is baseDir so archive paths are relative.
    $cwd = realpath($baseDir);
    if ($cwd === false) {
        $detail['error'] = 'Base directory not found';
        return false;
    }

    $relFolder = normalize_rel_path($relFolder);

    // 7z: -tzip uses Zip format, -mem=AES256 enables AES.
    // -pPASSWORD sets password, -y assume yes on all queries.
    // -mx=9 best compression.
    $zipPathQ = '"' . str_replace('"', '""', $zipPath) . '"';
    $relFolderQ = '"' . str_replace('"', '""', $relFolder) . '"';

    $sevenZipQ = quote_exe($sevenZip);
    $cmd = $sevenZipQ . " a -tzip -mem=AES256 -mx=9 -p" . escapeshellarg($password) . " -y {$zipPathQ} {$relFolderQ}";

    $out = [];
    $code = 1;
    try {
        run_command($cmd, $cwd, $out, $code);
    } catch (Throwable $e) {
        $detail['error'] = 'Failed to run 7z: ' . $e->getMessage();
        return false;
    }

    $detail['cmd'] = '7z ...';
    $detail['output'] = $out;
    $detail['encrypted'] = true;

    if ($code !== 0) {
        $detail['error'] = '7z exit code: ' . (string)$code;
        return false;
    }

    return true;
}

function zip_with_zipcmd(string $baseDir, string $relFolder, string $zipPath, string $password, bool $overwrite, array &$detail): bool {
    $zipCmd = detect_zip_cmd();
    if ($zipCmd === null) {
        $detail['error'] = 'zip command not found (install Info-ZIP / zip)';
        return false;
    }

    if (!$overwrite && file_exists($zipPath)) {
        $detail['error'] = 'Zip already exists and overwrite=0';
        return false;
    }
    if ($overwrite && file_exists($zipPath)) {
        @unlink($zipPath);
    }

    $cwd = realpath($baseDir);
    if ($cwd === false) {
        $detail['error'] = 'Base directory not found';
        return false;
    }

    $relFolder = normalize_rel_path($relFolder);

    $zipPathQ = '"' . str_replace('"', '""', $zipPath) . '"';
    $relFolderQ = '"' . str_replace('"', '""', $relFolder) . '"';

    // Info-ZIP: -P sets password non-interactively (ZipCrypto). Note: password may be visible in process list.
    $zipCmdQ = quote_exe($zipCmd);
    $cmd = $zipCmdQ . ' -r -q -P ' . escapeshellarg($password) . " {$zipPathQ} {$relFolderQ}";

    $out = [];
    $code = 1;
    try {
        run_command($cmd, $cwd, $out, $code);
    } catch (Throwable $e) {
        $detail['error'] = 'Failed to run zip: ' . $e->getMessage();
        return false;
    }

    $detail['cmd'] = 'zip ...';
    $detail['output'] = $out;
    $detail['encrypted'] = true;
    $detail['warning'] = 'zip -P uses ZipCrypto and may expose password via process list; prefer 7z(AES) when possible.';

    if ($code !== 0) {
        $detail['error'] = 'zip exit code: ' . (string)$code;
        return false;
    }

    return true;
}

function zip_add_dir_recursive(ZipArchive $zip, string $sourceDir, string $localPrefix, ?string $baseRealForExcludes, array $excludeRealPaths, array &$detail): void {
    $sourceReal = realpath($sourceDir);
    if ($sourceReal === false) {
        throw new RuntimeException('Source directory not found: ' . $sourceDir);
    }

    $localPrefix = normalize_rel_path($localPrefix);
    if ($localPrefix !== '') {
        $localPrefix .= '/';
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceReal, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    /** @var SplFileInfo $info */
    foreach ($it as $info) {
        $fullPath = $info->getPathname();
        $fullReal = realpath($fullPath);
        if ($fullReal === false) {
            continue;
        }
        foreach ($excludeRealPaths as $ex) {
            if ($ex !== '' && strcasecmp($fullReal, $ex) === 0) {
                continue 2;
            }
        }

        $rel = substr($fullPath, strlen($sourceReal) + 1);
        $rel = str_replace('\\', '/', (string)$rel);
        $localName = $localPrefix . $rel;

        if ($info->isDir()) {
            $zip->addEmptyDir(rtrim($localName, '/'));
            continue;
        }
        if (!$zip->addFile($fullPath, $localName)) {
            throw new RuntimeException('Failed to add file: ' . $fullPath);
        }
    }
}

function zip_bundle_ziparchive(string $outDir, string $mapFile, string $bundleZipPath, string $bundlePassword, bool $overwrite, bool $includeMap, array &$detail): bool {
    if (!class_exists('ZipArchive')) {
        $detail['error'] = 'ZipArchive not available';
        return false;
    }

    $zip = new ZipArchive();
    $flags = ZipArchive::CREATE;
    if ($overwrite) {
        $flags |= ZipArchive::OVERWRITE;
    } else {
        if (file_exists($bundleZipPath)) {
            $detail['error'] = 'Bundle zip already exists and overwrite=0';
            return false;
        }
    }

    $openRes = $zip->open($bundleZipPath, $flags);
    if ($openRes !== true) {
        $detail['error'] = 'Failed to open bundle zip (code=' . (string)$openRes . ')';
        return false;
    }

    $supportsEncryption = method_exists($zip, 'setEncryptionName')
        && method_exists($zip, 'setPassword')
        && (defined('ZipArchive::EM_AES_256') || defined('ZipArchive::EM_TRAD_PKWARE'));

    $encrypted = false;
    if ($supportsEncryption) {
        $zip->setPassword($bundlePassword);
    }

    $exclude = [];
    $bundleReal = realpath($bundleZipPath);
    if ($bundleReal !== false) {
        $exclude[] = $bundleReal;
    }

    try {
        zip_add_dir_recursive($zip, $outDir, 'zips', null, $exclude, $detail);

        if ($includeMap && is_file($mapFile)) {
            $zip->addFile($mapFile, 'zip_passwords.json');
        }

        if ($supportsEncryption) {
            // Encrypt all added files (best-effort).
            $method = defined('ZipArchive::EM_AES_256') ? ZipArchive::EM_AES_256 : ZipArchive::EM_TRAD_PKWARE;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name) || $name === '' || str_ends_with($name, '/')) {
                    continue;
                }
                if ($zip->setEncryptionName($name, $method)) {
                    $encrypted = true;
                }
            }
        }
    } catch (Throwable $e) {
        $zip->close();
        $detail['error'] = $e->getMessage();
        return false;
    }

    $zip->close();
    $detail['encrypted'] = $encrypted;
    if ($supportsEncryption && !$encrypted) {
        $detail['warning'] = 'Bundle: ZipArchive encryption API exists but no file was encrypted (libzip might lack crypto support)';
    } elseif (!$supportsEncryption) {
        $detail['warning'] = 'Bundle: ZipArchive encryption not supported in this PHP build; bundle zip is NOT encrypted';
    }
    return true;
}

function zip_bundle_7z(string $workspaceDir, string $outDir, string $mapFile, string $bundleZipPath, string $bundlePassword, bool $overwrite, bool $includeMap, array &$detail): bool {
    $sevenZip = detect_7z();
    if ($sevenZip === null) {
        $detail['error'] = '7z not found (install 7-Zip; script checks PATH and common install locations)';
        return false;
    }
    if (!$overwrite && file_exists($bundleZipPath)) {
        $detail['error'] = 'Bundle zip already exists and overwrite=0';
        return false;
    }
    $cwd = realpath($workspaceDir);
    if ($cwd === false) {
        $detail['error'] = 'Workspace dir not found';
        return false;
    }

    $bundleZipReal = realpath(dirname($bundleZipPath));
    if ($bundleZipReal !== false) {
        $bundleZipPath = rtrim($bundleZipReal, "\\/ ") . DS . basename($bundleZipPath);
    }
    $bundleZipPathQ = '"' . str_replace('"', '""', $bundleZipPath) . '"';

    $outReal = realpath($outDir);
    if ($outReal === false) {
        $detail['error'] = 'Output directory not found: ' . $outDir;
        return false;
    }

    $sources = [];
    $sources[] = '"' . str_replace('"', '""', $outReal) . '"';

    if ($includeMap && is_file($mapFile)) {
        $mapReal = realpath($mapFile);
        if ($mapReal !== false) {
            $sources[] = '"' . str_replace('"', '""', $mapReal) . '"';
        }
    }
    $sourcesStr = implode(' ', $sources);

    $sevenZipQ = quote_exe($sevenZip);
    $cmd = $sevenZipQ . " a -tzip -mem=AES256 -mx=9 -p" . escapeshellarg($bundlePassword) . " -y {$bundleZipPathQ} {$sourcesStr}";
    $out = [];
    $code = 1;
    try {
        run_command($cmd, $cwd, $out, $code);
    } catch (Throwable $e) {
        $detail['error'] = 'Failed to run 7z: ' . $e->getMessage();
        return false;
    }
    $detail['cmd'] = '7z ...';
    $detail['output'] = $out;
    $detail['encrypted'] = true;
    if ($code !== 0) {
        $detail['error'] = '7z exit code: ' . (string)$code;
        return false;
    }
    return true;
}

function main(): int {
    $opts = getopt('', [
        'base::',
        'depth::',
        'out::',
        'map::',
        'algo::',
        'overwrite::',
        'print-passwords::',
        'bundle::',
        'bundle-name::',
        'bundle-password::',
        'bundle-include-map::',
        'txt::',
        'csv::',
        'csv-bom::',
        'nest::',
        'nest-parent-encrypt::',
    ]);

    $baseDir = isset($opts['base']) && $opts['base'] !== false ? (string)$opts['base'] : (__DIR__ . DS . 'files');
    $depth = isset($opts['depth']) && $opts['depth'] !== false ? (int)$opts['depth'] : 1;
    $outDir = isset($opts['out']) && $opts['out'] !== false ? (string)$opts['out'] : (__DIR__ . DS . 'zips');
    $mapFile = isset($opts['map']) && $opts['map'] !== false ? (string)$opts['map'] : (__DIR__ . DS . 'zip_passwords.json');
    $algo = isset($opts['algo']) && $opts['algo'] !== false ? strtolower((string)$opts['algo']) : 'auto';
    $overwrite = isset($opts['overwrite']) && $opts['overwrite'] !== false ? ((int)$opts['overwrite'] === 1) : false;
    $printPwds = isset($opts['print-passwords']) && $opts['print-passwords'] !== false ? ((int)$opts['print-passwords'] === 1) : false;
    $bundle = isset($opts['bundle']) && $opts['bundle'] !== false ? ((int)$opts['bundle'] === 1) : false;
    $bundleName = isset($opts['bundle-name']) && $opts['bundle-name'] !== false ? (string)$opts['bundle-name'] : '';
    $bundlePwd = isset($opts['bundle-password']) && $opts['bundle-password'] !== false ? (string)$opts['bundle-password'] : '';
    $bundleIncludeMap = isset($opts['bundle-include-map']) && $opts['bundle-include-map'] !== false ? ((int)$opts['bundle-include-map'] === 1) : false;
    $txtPath = isset($opts['txt']) && $opts['txt'] !== false ? (string)$opts['txt'] : '';
    $csvPath = isset($opts['csv']) && $opts['csv'] !== false ? (string)$opts['csv'] : '';
    $csvBom = isset($opts['csv-bom']) && $opts['csv-bom'] !== false ? ((int)$opts['csv-bom'] === 1) : true;
    $nest = isset($opts['nest']) && $opts['nest'] !== false ? ((int)$opts['nest'] === 1) : false;
    $nestParentEncrypt = isset($opts['nest-parent-encrypt']) && $opts['nest-parent-encrypt'] !== false ? ((int)$opts['nest-parent-encrypt'] === 1) : true;

    if ($depth < 1) {
        cli_err('depth must be >= 1');
        return 2;
    }

    $baseReal = realpath($baseDir);
    if ($baseReal === false || !is_dir($baseReal)) {
        cli_err('Base directory does not exist: ' . $baseDir);
        return 2;
    }

    ensure_dir($outDir);

    $outReal = realpath($outDir);
    $exclude = $outReal !== false ? $outReal : null;

    cli_out('Base: ' . $baseReal);
    cli_out('Depth: ' . (string)$depth);
    cli_out('Output: ' . $outDir);
    cli_out('Map: ' . $mapFile);
    cli_out('Algo: ' . $algo);
    cli_out('Bundle: ' . ($bundle ? '1' : '0'));
    cli_out('Nest: ' . ($nest ? '1' : '0'));
    if ($nest) {
        cli_out('NestParentEncrypt: ' . ($nestParentEncrypt ? '1' : '0'));
    }

    $entries = [];
    $success = 0;
    $failed = 0;

    if (!$nest) {
        $dirs = list_dirs_at_depth($baseReal, $depth, $exclude);
        if (empty($dirs)) {
            cli_out('No directories found at the requested depth.');
        }

        foreach ($dirs as $dirPath) {
            $rel = substr($dirPath, strlen($baseReal));
            $rel = ltrim((string)$rel, DS);
            $relNorm = normalize_rel_path($rel);

            $pwd = rand_password(18);

            $baseName = basename($dirPath);
            $zipFile = safe_filename($baseName) . '__' . substr(md5($relNorm), 0, 10) . '.zip';
            $zipPath = rtrim($outDir, "\\/ ") . DS . $zipFile;

            $detail = [
                'folder' => $relNorm,
                'zip' => $zipPath,
                'method' => null,
                'encrypted' => false,
                'status' => 'error',
            ];

            $ok = false;
            $chosen = $algo;

            if ($chosen === 'auto') {
                if (class_exists('ZipArchive')) {
                    $chosen = 'ziparchive';
                } elseif (detect_7z() !== null) {
                    $chosen = '7z';
                } elseif (detect_zip_cmd() !== null) {
                    $chosen = 'zip';
                } else {
                    $chosen = '7z';
                }
            }

            if ($chosen === 'ziparchive') {
                $detail['method'] = 'ziparchive';
                $ok = zip_with_ziparchive($baseReal, $dirPath, $zipPath, $relNorm, $pwd, $overwrite, $detail);
                if (!$ok && $algo === 'auto') {
                    // fallback to 7z
                    $chosen = '7z';
                }
            }

            if (!$ok && $chosen === '7z') {
                $detail['method'] = '7z';
                $ok = zip_with_7z($baseReal, $relNorm, $zipPath, $pwd, $overwrite, $detail);
            }

            if (!$ok && $chosen === 'zip') {
                $detail['method'] = 'zipcmd';
                $ok = zip_with_zipcmd($baseReal, $relNorm, $zipPath, $pwd, $overwrite, $detail);
            }

            if ($ok) {
                $detail['status'] = 'success';
                $success++;
                cli_out('[OK] ' . $relNorm . ' -> ' . $zipFile);
            } else {
                $failed++;
                cli_err('[FAIL] ' . $relNorm . ' -> ' . ($detail['error'] ?? 'unknown error'));
            }

            if ($printPwds) {
                cli_out('  password: ' . $pwd);
            }

            // Always record password in mapping.
            $detail['password'] = $pwd;
            $detail['createdAt'] = date('c');

            $entries[$relNorm] = $detail;
        }
    } else {
        if (!class_exists('ZipArchive') && !$nestParentEncrypt) {
            cli_err('nest=1 with nest-parent-encrypt=0 requires ZipArchive (PHP zip extension).');
            return 2;
        }

        if ($nestParentEncrypt) {
            if (detect_7z() === null && detect_zip_cmd() === null) {
                cli_err('nest-parent-encrypt=1 requires a zip tool: install 7-Zip (recommended) or the `zip` command, or set --nest-parent-encrypt=0.');
                return 2;
            }
        }

        $dirs = list_dirs_up_to_depth($baseReal, $depth, $exclude);
        if (empty($dirs)) {
            cli_out('No directories found up to the requested depth.');
        }

        // Build nodes by relative path.
        $nodes = [];
        foreach ($dirs as $dirPath) {
            $rel = substr($dirPath, strlen($baseReal));
            $rel = ltrim((string)$rel, DS);
            $relNorm = normalize_rel_path($rel);
            if ($relNorm === '') {
                continue;
            }
            $nodes[$relNorm] = [
                'real' => $dirPath,
                'depth' => rel_depth($relNorm),
                'children' => [],
            ];
        }

        // Link children.
        foreach (array_keys($nodes) as $relNorm) {
            $parent = dirname($relNorm);
            if ($parent === '.' || $parent === DIRECTORY_SEPARATOR) {
                $parent = '';
            }
            $parent = normalize_rel_path($parent);
            if ($parent !== '' && isset($nodes[$parent])) {
                $nodes[$parent]['children'][] = $relNorm;
            }
        }
        foreach ($nodes as &$n) {
            if (is_array($n['children'])) {
                sort($n['children']);
            }
        }
        unset($n);

        // Bottom-up (deepest first) so parent can include child zips.
        $rels = array_keys($nodes);
        usort($rels, function(string $a, string $b) use ($nodes): int {
            $da = (int)($nodes[$a]['depth'] ?? 0);
            $db = (int)($nodes[$b]['depth'] ?? 0);
            if ($da !== $db) {
                return $db <=> $da;
            }
            return strcmp($a, $b);
        });

        $zipPaths = [];
        foreach ($rels as $relNorm) {
            $dirPath = (string)$nodes[$relNorm]['real'];
            $children = $nodes[$relNorm]['children'] ?? [];

            $pwd = rand_password(18);
            $baseName = basename($dirPath);
            $zipFile = safe_filename($baseName) . '__' . substr(md5($relNorm), 0, 10) . '.zip';
            $zipPath = rtrim($outDir, "\\/ ") . DS . $zipFile;

            $detail = [
                'folder' => $relNorm,
                'zip' => $zipPath,
                'method' => 'ziparchive',
                'encrypted' => false,
                'status' => 'error',
                'nest' => true,
            ];

            $ok = false;
            if (is_array($children) && !empty($children)) {
                $childZips = [];
                foreach ($children as $childRel) {
                    if (!isset($zipPaths[$childRel])) {
                        $detail['error'] = 'Child zip not built: ' . $childRel;
                        $ok = false;
                        break;
                    }
                    $childFolderName = basename($childRel);
                    $childZips[$childFolderName] = $zipPaths[$childRel];
                }
                if (!isset($detail['error'])) {
                    if ($nestParentEncrypt) {
                        // Use 7z to create encrypted parent zip reliably.
                        $ok = zip_parent_with_7z_from_staging($dirPath, $zipPath, $relNorm, $pwd, $overwrite, $childZips, $detail);
                    } else {
                        $ok = zip_with_ziparchive_nested_children($dirPath, $zipPath, $relNorm, $pwd, $overwrite, $childZips, $detail);
                    }
                }
            } else {
                // Leaf: include everything recursively (including deeper levels beyond max depth).
                // Allow leaf zips to use selected algo (ZipArchive or 7z) for encryption.
                $chosen = $algo;
                if ($chosen === 'auto') {
                    if (class_exists('ZipArchive')) {
                        $chosen = 'ziparchive';
                    } elseif (detect_7z() !== null) {
                        $chosen = '7z';
                    } elseif (detect_zip_cmd() !== null) {
                        $chosen = 'zip';
                    } else {
                        $chosen = '7z';
                    }
                }
                if ($chosen === 'ziparchive') {
                    $detail['method'] = 'ziparchive';
                    $ok = zip_with_ziparchive($baseReal, $dirPath, $zipPath, $relNorm, $pwd, $overwrite, $detail);
                    if (!$ok && $algo === 'auto') {
                        $chosen = '7z';
                    }
                }
                if (!$ok && $chosen === '7z') {
                    $detail['method'] = '7z';
                    $ok = zip_with_7z($baseReal, $relNorm, $zipPath, $pwd, $overwrite, $detail);
                }
                if (!$ok && $chosen === 'zip') {
                    $detail['method'] = 'zipcmd';
                    $ok = zip_with_zipcmd($baseReal, $relNorm, $zipPath, $pwd, $overwrite, $detail);
                }
            }

            if ($ok) {
                $detail['status'] = 'success';
                $success++;
                $zipPaths[$relNorm] = $zipPath;
                cli_out('[OK] ' . $relNorm . ' -> ' . $zipFile);
            } else {
                $failed++;
                cli_err('[FAIL] ' . $relNorm . ' -> ' . ($detail['error'] ?? 'unknown error'));
            }

            if ($printPwds) {
                cli_out('  password: ' . $pwd);
            }

            $detail['password'] = $pwd;
            $detail['createdAt'] = date('c');
            $entries[$relNorm] = $detail;
        }
    }

    $map = [
        'generatedAt' => date('c'),
        'baseDir' => $baseReal,
        'depth' => $depth,
        'outDir' => realpath($outDir) ?: $outDir,
        'algo' => $algo,
        'overwrite' => $overwrite,
        'nest' => $nest,
        'nestParentEncrypt' => $nest ? $nestParentEncrypt : null,
        'entries' => $entries,
        'bundle' => null,
        'summary' => [
            'total' => count($entries),
            'success' => $success,
            'failed' => $failed,
        ],
    ];

    $writeMap = function(array $mapToWrite) use ($mapFile): bool {
        $json = json_encode($mapToWrite, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            cli_err('Failed to encode JSON: ' . json_last_error_msg());
            return false;
        }
        $mapDir = dirname($mapFile);
        if ($mapDir !== '' && $mapDir !== '.' && !is_dir($mapDir)) {
            ensure_dir($mapDir);
        }
        return file_put_contents($mapFile, $json . PHP_EOL) !== false;
    };

    if (!$writeMap($map)) {
        cli_err('Failed to write map file: ' . $mapFile);
        return 1;
    }

    if ($bundle) {
        $bundlePwd = trim($bundlePwd);
        if ($bundlePwd === '') {
            $bundlePwd = rand_password(18);
        }
        $bundleName = trim($bundleName);
        if ($bundleName === '') {
            $bundleName = 'zips_bundle_' . date('Ymd_His') . '.zip';
        }
        $bundleName = safe_filename($bundleName);
        if (!str_ends_with(strtolower($bundleName), '.zip')) {
            $bundleName .= '.zip';
        }

        // Put the bundle zip alongside the outDir (workspace root by default) to avoid zipping itself.
        $workspaceDir = __DIR__;
        $bundleZipPath = rtrim($workspaceDir, "\\/ ") . DS . $bundleName;

        $bundleDetail = [
            'zip' => $bundleZipPath,
            'method' => null,
            'encrypted' => false,
            'status' => 'error',
        ];

        $okBundle = false;
        $chosen = $algo;
        if ($chosen === 'auto') {
            $chosen = class_exists('ZipArchive') ? 'ziparchive' : '7z';
        }

        if ($chosen === 'ziparchive') {
            $bundleDetail['method'] = 'ziparchive';
            $okBundle = zip_bundle_ziparchive($outDir, $mapFile, $bundleZipPath, $bundlePwd, $overwrite, $bundleIncludeMap, $bundleDetail);
            if (!$okBundle && $algo === 'auto') {
                $chosen = '7z';
            }
        }
        if (!$okBundle && $chosen === '7z') {
            $bundleDetail['method'] = '7z';
            $okBundle = zip_bundle_7z($workspaceDir, $outDir, $mapFile, $bundleZipPath, $bundlePwd, $overwrite, $bundleIncludeMap, $bundleDetail);
        }

        if ($okBundle) {
            $bundleDetail['status'] = 'success';
            cli_out('[OK] bundle -> ' . basename($bundleZipPath));
        } else {
            cli_err('[FAIL] bundle -> ' . ($bundleDetail['error'] ?? 'unknown error'));
        }

        $bundleDetail['password'] = $bundlePwd;
        $bundleDetail['includeMap'] = $bundleIncludeMap;
        $bundleDetail['createdAt'] = date('c');
        $map['bundle'] = $bundleDetail;

        // Rewrite map with bundle info (note: the JSON inside bundle, if included, is the pre-bundle version).
        if (!$writeMap($map)) {
            cli_err('Failed to update map file with bundle info: ' . $mapFile);
            return 1;
        }
    }

    if (trim($txtPath) !== '') {
        write_password_txt($txtPath, $map, true);
        cli_out('TXT written: ' . $txtPath);
    }

    if (trim($csvPath) !== '') {
        write_password_csv($csvPath, $map, true, $csvBom);
        cli_out('CSV written: ' . $csvPath);
    }

    cli_out('Done. success=' . (string)$success . ' failed=' . (string)$failed);
    cli_out('Mapping written: ' . $mapFile);

    return $failed === 0 ? 0 : 3;
}

try {
    $code = main();
    if (is_cli()) {
        exit($code);
    }
} catch (Throwable $e) {
    cli_err('Fatal: ' . $e->getMessage());
    if (is_cli()) {
        exit(1);
    }
}

<?php

declare(strict_types=1);

/**
 * Every class a source file names resolves.
 *
 * `php -l` does not resolve names: a class used without its `use` passes lint and fails only when
 * the line runs. CallsController named EstateAnalytics without importing it (2026-10-30), so every
 * call disposition was saved and then answered 500 — Class "Aicountly\Api\Controllers\EstateAnalytics"
 * not found. This reads every file under src/ and refuses an unqualified class name (`X::`, `new X`,
 * `instanceof X`, `catch (X`) that is neither imported, nor a file in the file's own namespace, nor
 * declared in that file. The same check as crm-aicountly / helpdesk-aicountly.
 *
 *   php server-php/tests/class_names.php
 *
 * No database, no network.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$src = dirname(__DIR__) . '/src';
$unresolved = [];
$files = 0;

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $files++;
    $code = (string) file_get_contents($file->getPathname());
    $tokens = array_values(array_filter(
        PhpToken::tokenize($code),
        static fn (PhpToken $t): bool => !$t->isIgnorable() && $t->id !== T_COMMENT && $t->id !== T_DOC_COMMENT,
    ));

    $namespace = '';
    $imports = [];
    $depth = 0;
    foreach ($tokens as $i => $t) {
        $depth += $t->text === '{' ? 1 : ($t->text === '}' ? -1 : 0);
        if ($t->id === T_NAMESPACE && in_array($tokens[$i + 1]->id ?? null, [T_NAME_QUALIFIED, T_STRING], true)) {
            $namespace = $tokens[$i + 1]->text;
        } elseif ($t->id === T_USE && $depth === 0 && !in_array($tokens[$i + 1]->text ?? '', ['function', 'const'], true)) {
            $name = ltrim($tokens[$i + 1]->text, '\\');
            $alias = ($tokens[$i + 2]->id ?? null) === T_AS ? $tokens[$i + 3]->text : substr((string) strrchr('\\' . $name, '\\'), 1);
            $imports[strtolower($alias)] = true;
        }
    }
    if ($namespace === '') {
        continue; // a script or a vendored file with no namespace: global names resolve as written
    }

    foreach ($tokens as $i => $t) {
        $prev = $tokens[$i - 1] ?? null;
        $isClass = $t->id === T_STRING && (
            ($tokens[$i + 1]->id ?? null) === T_DOUBLE_COLON
            || in_array($prev?->id, [T_NEW, T_INSTANCEOF], true)
            || (in_array($prev?->text, ['(', '|'], true) && ($tokens[$i - 2]->id ?? null) === T_CATCH)
        );
        if (!$isClass || in_array(strtolower($t->text), ['self', 'static', 'parent'], true) || isset($imports[strtolower($t->text)])) {
            continue;
        }
        $fq = $namespace . '\\' . $t->text;
        $sameNamespaceFile = str_starts_with($fq, 'Aicountly\\Api\\')
            && is_file($src . '/' . str_replace('\\', '/', substr($fq, strlen('Aicountly\\Api\\'))) . '.php');
        if ($sameNamespaceFile || preg_match('~\b(class|interface|trait|enum)\s+' . preg_quote($t->text, '~') . '\b~', $code) === 1) {
            continue;
        }
        $unresolved[] = substr($file->getPathname(), strlen($src) + 1) . ':' . $t->line . ' ' . $t->text . ' (would load ' . $fq . ')';
    }
}

$ok = $files > 100 && $unresolved === [];
echo "class names: {$files} files under src/ read\n";
foreach ($unresolved as $line) {
    echo "  FAIL unresolved class name: {$line}\n";
}
echo $ok ? "  ok   every unqualified class name is imported, in its own namespace, or declared there\n" : '';
echo "\n" . ($ok ? 1 : 0) . ' passed, ' . ($ok ? 0 : 1) . " failed\n";
exit($ok ? 0 : 1);

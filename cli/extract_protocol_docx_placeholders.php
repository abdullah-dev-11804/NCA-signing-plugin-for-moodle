<?php

$options = getopt('h', ['docx:', 'outfile::', 'format::', 'help']);
$help = isset($options['h']) || isset($options['help']) || empty($options['docx']);

if ($help) {
    $helptext = "Extract red placeholder runs from a protocol DOCX template.\n\n"
        . "Options:\n"
        . "--docx=/path/template.docx   Input DOCX file (required)\n"
        . "--outfile=/path/output       Optional output file\n"
        . "--format=markdown|json       Output format; default markdown\n"
        . "-h, --help                   Print this help\n";
    fwrite(STDOUT, $helptext);
    exit(0);
}

$docxpath = (string)$options['docx'];
if (!is_file($docxpath)) {
    fwrite(STDERR, 'DOCX file not found: ' . $docxpath . PHP_EOL);
    exit(1);
}

$documentxml = '';
if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    if ($zip->open($docxpath) !== true) {
        fwrite(STDERR, 'Unable to open DOCX file: ' . $docxpath . PHP_EOL);
        exit(1);
    }
    $documentxml = (string)$zip->getFromName('word/document.xml');
    $zip->close();
} else {
    $tmpdir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ncasign_docx_' . uniqid('', true);
    mkdir($tmpdir, 0777, true);
    $tmpzip = $tmpdir . DIRECTORY_SEPARATOR . 'template.zip';
    copy($docxpath, $tmpzip);
    $command = 'powershell -NoProfile -Command '
        . escapeshellarg("Expand-Archive -LiteralPath '$tmpzip' -DestinationPath '$tmpdir\\expanded' -Force");
    @exec($command, $outputlines, $exitcode);
    if ($exitcode !== 0) {
        fwrite(STDERR, 'Unable to extract DOCX archive without ZipArchive.' . PHP_EOL);
        exit(1);
    }
    $xmlpath = $tmpdir . DIRECTORY_SEPARATOR . 'expanded' . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . 'document.xml';
    if (is_file($xmlpath)) {
        $documentxml = (string)file_get_contents($xmlpath);
    }
}

if ($documentxml === false || trim($documentxml) === '') {
    fwrite(STDERR, 'word/document.xml not found in DOCX: ' . $docxpath . PHP_EOL);
    exit(1);
}

$doc = new DOMDocument();
$doc->loadXML($documentxml);

$xpath = new DOMXPath($doc);
$xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

$inventory = [];
foreach ($xpath->query('//w:p') as $paragraph) {
    $fullparts = [];
    $redparts = [];

    foreach ($xpath->query('./w:r', $paragraph) as $run) {
        $textnodes = $xpath->query('./w:t', $run);
        if ($textnodes->length === 0) {
            continue;
        }

        $text = '';
        foreach ($textnodes as $textnode) {
            $text .= $textnode->nodeValue;
        }
        if ($text === '') {
            continue;
        }

        $fullparts[] = $text;
        $colornode = $xpath->query('./w:rPr/w:color', $run)->item(0);
        $color = $colornode instanceof DOMElement ? strtoupper((string)$colornode->getAttributeNS(
            'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
            'val'
        )) : '';
        if ($color === 'FF0000') {
            $redparts[] = $text;
        }
    }

    if (!$redparts) {
        continue;
    }

    $inventory[] = [
        'paragraph' => trim(preg_replace('/\s+/u', ' ', implode('', $fullparts))),
        'red' => trim(preg_replace('/\s+/u', ' ', implode('', $redparts))),
    ];
}

$format = strtolower((string)($options['format'] ?? 'markdown'));
if ($format !== 'json') {
    $format = 'markdown';
}

if ($format === 'json') {
    $output = json_encode([
        'docx' => $docxpath,
        'placeholderCount' => count($inventory),
        'placeholders' => $inventory,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} else {
    $lines = [];
    $lines[] = '# Protocol DOCX Red Placeholder Inventory';
    $lines[] = '';
    $lines[] = '- Source: `' . $docxpath . '`';
    $lines[] = '- Placeholder paragraphs: `' . count($inventory) . '`';
    $lines[] = '';
    foreach ($inventory as $index => $item) {
        $lines[] = '## Placeholder ' . ($index + 1);
        $lines[] = '';
        $lines[] = '- Paragraph: `' . str_replace('`', '\`', $item['paragraph']) . '`';
        $lines[] = '- Red text: `' . str_replace('`', '\`', $item['red']) . '`';
        $lines[] = '';
    }
    $output = implode(PHP_EOL, $lines);
}

$outfile = trim((string)$options['outfile']);
if ($outfile !== '') {
    file_put_contents($outfile, $output);
    fwrite(STDOUT, 'Placeholder inventory written: ' . $outfile . PHP_EOL);
} else {
    fwrite(STDOUT, $output . PHP_EOL);
}

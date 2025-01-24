<?php

declare(strict_types=1);

namespace Ai\Infrastruture\Services;

use Ai\Infrastruture\Exceptions\UnreadableDocumentException;
use DOMDocument;
use DOMNode;
use DOMXPath;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Smalot\PdfParser\Parser;
use Throwable;

class DocumentReader
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
    ) {}

    public function readFromUrl(string $url, ?int $max = null): ?string
    {
        $request = $this->requestFactory->createRequest('GET', $url);
        $response = $this->client->sendRequest($request);

        $contents = $response->getBody()->getContents();

        // Extract extension from Content-Type header
        $contentType = $response->getHeaderLine('Content-Type');
        $ext = match (true) {
            str_contains($contentType, 'application/pdf') => 'pdf',
            str_contains($contentType, 'application/msword') => 'doc',
            str_contains($contentType, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') => 'docx',
            str_contains($contentType, 'application/vnd.oasis.opendocument.text') => 'odt',
            str_contains($contentType, 'text/html') => 'html',
            str_contains($contentType, 'application/xml') || str_contains($contentType, 'text/xml') => 'xml',
            str_contains($contentType, 'application/json') => 'json',
            str_contains($contentType, 'text/plain') => 'txt',
            default => null
        };

        return $this->read($contents, $ext, $max);
    }

    public function read(
        string $contents,
        ?string $ext = null,
        ?int $max = null
    ): ?string {
        if ($max !== null && $max < 0) {
            $max = null;
        }

        if ($ext === 'pdf') {
            return $this->readPdf($contents);
        }

        if (in_array($ext, ['docx', 'doc', 'odt'])) {
            return $this->readDoc($contents, $ext);
        }

        if (
            stripos(trim($contents), '<?xml') === 0
            || $ext === 'xml'
        ) {
            return $this->readXml($contents, $max);
        }

        if (
            stripos(trim($contents), '<!doctype html') === 0
            || in_array($ext, ['html', 'xhtml', 'htm'])
        ) {
            return $this->readHtml($contents, $max);
        }

        if (
            in_array($ext, ['json', 'txt'])
            || ctype_print(str_replace(["\n", "\r", "\t"], '', $contents))
        ) {
            // If the content is already text, return it as is
            return $contents;
        }

        return null;
    }

    public function readPdf(string $contents, ?int $max = null): string
    {
        try {
            $parser = new Parser();
            $pdf = $parser->parseContent($contents);
            $text = $pdf->getText();
        } catch (Throwable $th) {
            throw new UnreadableDocumentException(
                message: $th->getMessage(),
                previous: $th
            );
        }

        return is_null($max) ? $text : mb_substr($text, 0, $max);
    }

    public function readDoc(string $contents, string $ext, ?int $max = null): string
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'PHPWord');
        file_put_contents($temporaryFile, $contents);

        $type = match (true) {
            $ext == 'docx' => 'Word2007',
            $ext == 'doc' => 'MsDoc',
            $ext == 'odt' => 'ODText',
            default => 'Word2007',
        };

        try {
            $doc = IOFactory::load($temporaryFile, $type);
            $fullText = '';
            foreach ($doc->getSections() as $section) {
                $fullText .= $this->extractTextFromDocxNode($section);
            }
        } catch (Throwable $th) {
            throw new UnreadableDocumentException(
                message: $th->getMessage(),
                previous: $th
            );
        }

        unlink($temporaryFile);
        return is_null($max) ? $fullText : mb_substr($fullText, 0, $max);
    }

    private function extractTextFromDocxNode(Section|AbstractElement $section): string
    {
        $text = '';
        if (method_exists($section, 'getElements')) {
            foreach ($section->getElements() as $childSection) {
                $text = $this->concatenate($text, $this->extractTextFromDocxNode($childSection));
            }
        } elseif (method_exists($section, 'getText')) {
            $text = $this->concatenate($text, $this->toString($section->getText()));
        }

        return $text;
    }

    private function concatenate(string $text1, string $text2): string
    {
        if ($text1 === '') {
            return $text1 . $text2;
        }

        if (str_ends_with($text1, ' ')) {
            return $text1 . $text2;
        }

        if (str_starts_with($text2, ' ')) {
            return $text1 . $text2;
        }

        return $text1 . ' ' . $text2;
    }

    /**
     * @param  array<string>|string|null  $text
     */
    private function toString(array|null|string $text): string
    {
        if ($text === null) {
            return '';
        }

        if (is_array($text)) {
            return implode(' ', $text);
        }

        return $text;
    }

    public function readXml(string $xml, ?int $max = null): string
    {
        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new DOMXPath($dom);

        // Get all elements
        $elements = $xpath->query('//*');
        $structuredContent = [];
        $length = 0;

        foreach ($elements as $element) {
            // Get only direct text content (excluding child elements)
            $directTextContent = '';
            foreach ($element->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                    $directTextContent .= $child->nodeValue;
                }
            }

            $directTextContent = trim($directTextContent);
            if (!$directTextContent) {
                continue;
            }

            $path = $this->getXmlPath($element);

            // Check if adding this element would exceed max length
            $line = "{$path}: {$directTextContent}";
            $delta = mb_strlen($line);

            if ($max !== null && $length + $delta > $max) {
                break;
            }

            $structuredContent[] = $line;
            $length += $delta;

            // Add attributes if present
            if ($element->hasAttributes()) {
                foreach ($element->attributes as $attr) {
                    $line = "{$path}/@{$attr->name}: {$attr->value}";
                    $delta = mb_strlen($line);

                    if ($max !== null && $length + $delta > $max) {
                        break;
                    }

                    $structuredContent[] = $line;
                    $length += $delta;
                }
            }
        }

        return implode("\n", $structuredContent);
    }

    private function getXmlPath(DOMNode $node): string
    {
        $path = '';
        while ($node && $node->nodeType === XML_ELEMENT_NODE) {
            $position = 1;
            $previousSibling = $node->previousSibling;

            while ($previousSibling) {
                if ($previousSibling->nodeName === $node->nodeName) {
                    $position++;
                }
                $previousSibling = $previousSibling->previousSibling;
            }

            $path = '/' . $node->nodeName . '[' . $position . ']' . $path;
            $node = $node->parentNode;
        }
        return $path;
    }

    public function readHtml(string $html, ?int $max = null): string
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $xpath = new DOMXPath($dom);

        // Extract all text nodes
        $textNodes = $xpath->query("//text()[normalize-space()]");
        $textContent = "";
        $length = 0;
        foreach ($textNodes as $node) {
            $value = trim($node->nodeValue);
            $delta = mb_strlen($value);

            if ($max !== null && $length + $delta > $max) {
                break;
            }

            $textContent .= $value . " ";
            $length += $delta;
        }

        // Extract JSON data from script tags
        $scriptNodes = $xpath->query("//script[@type='application/json']");
        $jsonContent = [];
        foreach ($scriptNodes as $node) {
            $delta = mb_strlen($node->nodeValue);
            if ($max !== null && $length + $delta > $max) {
                break;
            }

            $jsonContent[] = json_decode($node->nodeValue, true);
            $length += $delta;
        }

        // Combine text and JSON data into a meaningful format
        $formattedContent = trim($textContent) . "\n\n";
        if (!empty($jsonContent)) {
            $formattedContent .= json_encode($jsonContent) . "\n";
        }

        return $formattedContent;
    }
}

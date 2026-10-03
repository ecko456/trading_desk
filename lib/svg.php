<?php
declare(strict_types=1);

/*
 * SVG obrázky strategií.
 *
 * SVG je text a umí obsahovat skripty, obsluhu událostí nebo odkazy na cizí servery.
 * Proto se před uložením přečte a znovu sestaví jen z povolených prvků a atributů
 * (kresba, text, přechody, značky). Skripty, vložené HTML, animace, externí odkazy
 * i DOCTYPE s entitami se zahodí nebo celý soubor odmítne. file.php navíc SVG vydává
 * s politikou, která v něm nic nespustí.
 */

const SVG_MAX_BYTES = 5 * 1024 * 1024;
const SVG_MAX_NODES = 60000;
const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';
const SVG_XLINK = 'http://www.w3.org/1999/xlink';

/** Povolené prvky (jen kresba a text); ostatní se z obrázku odstraní i s obsahem. */
const SVG_ELEMENTS = [
    'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'marker', 'style',
    'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'image',
    'text', 'tspan', 'textPath',
    'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask', 'pattern',
];

/** Cesta k obrázku vložená přímo v souboru; jiný odkaz (http, soubor, skript) se zahodí. */
const SVG_INLINE_IMAGE = '#^data:image/(png|jpe?g|webp|gif);base64,[A-Za-z0-9+/=\s]+$#';

/** Přijme SVG ze souboru a vrátí bezpečnou kopii, jinak vyhodí InvalidArgumentException. */
function sanitize_svg(string $raw): string
{
    if (strlen($raw) > SVG_MAX_BYTES) {
        throw new InvalidArgumentException('SVG obrázek může mít nejvýš 5 MB.');
    }
    if (!class_exists(DOMDocument::class)) {
        throw new InvalidArgumentException('Server zatím neumí číst SVG (chybí rozšíření PHP php-xml). Spusť znovu instalaci, nebo nahraj PNG.');
    }
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    // Entity a vlastní deklarace v obrázku nejsou potřeba a otevírají útoky (XXE, „billion laughs“).
    // Prostý řádek <!DOCTYPE svg PUBLIC …> (Illustrator, starší Inkscape) projde; DTD se nenačítá.
    if (preg_match('/<!(ENTITY|ATTLIST|ELEMENT|NOTATION)/i', $raw)) {
        throw new InvalidArgumentException('SVG s vlastními entitami nebo DTD nejde nahrát. Ulož ho jako prosté SVG.');
    }
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadXML($raw, LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($loaded && $document->doctype !== null && (trim((string)$document->doctype->internalSubset) !== '' || $document->doctype->entities->length > 0)) {
        throw new InvalidArgumentException('SVG s vlastními entitami nebo DTD nejde nahrát. Ulož ho jako prosté SVG.');
    }
    $root = $document->documentElement;
    if (!$loaded || $root === null || $root->localName !== 'svg' || $root->namespaceURI !== SVG_NAMESPACE) {
        throw new InvalidArgumentException('Soubor není platný SVG obrázek.');
    }
    if ($document->getElementsByTagName('*')->length > SVG_MAX_NODES) {
        throw new InvalidArgumentException('SVG obrázek je příliš složitý.');
    }
    svg_clean_node($root);
    // Instrukce zpracování (třeba xml-stylesheet s odkazem ven) mimo kořen se nezapíšou.
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $document->saveXML($root) . "\n";
}

/** Soubor se nahrává jako SVG, když ho tak pozná fileinfo, nebo má příponu .svg a je to text (dlouhý úvodní komentář). */
function upload_is_svg(string $mime, string $fileName): bool
{
    return $mime === 'image/svg+xml'
        || (in_array($mime, ['text/xml', 'application/xml', 'text/plain'], true) && (bool)preg_match('/\.svg$/i', $fileName));
}

function svg_clean_node(DOMElement $element): void
{
    foreach (iterator_to_array($element->attributes) as $attribute) {
        if (!svg_attribute_allowed($element, $attribute)) {
            $element->removeAttributeNode($attribute);
        }
    }
    if ($element->localName === 'style' && !svg_css_safe($element->textContent)) {
        $element->parentNode?->removeChild($element);
        return;
    }
    foreach (iterator_to_array($element->childNodes) as $child) {
        if ($child instanceof DOMElement) {
            if ($child->namespaceURI !== SVG_NAMESPACE || !in_array($child->localName, SVG_ELEMENTS, true)) {
                $element->removeChild($child);
                continue;
            }
            svg_clean_node($child);
        } elseif ($child instanceof DOMProcessingInstruction || $child instanceof DOMEntityReference) {
            $element->removeChild($child);
        }
    }
}

function svg_attribute_allowed(DOMElement $element, DOMAttr $attribute): bool
{
    $name = strtolower($attribute->localName);
    $namespace = $attribute->namespaceURI;
    $value = trim($attribute->value);
    // Atributy editorů (inkscape:, sodipodi:) a jiných jmenných prostorů nejsou potřeba.
    if ($namespace !== null && $namespace !== SVG_XLINK && $namespace !== 'http://www.w3.org/XML/1998/namespace') {
        return false;
    }
    if (str_starts_with($name, 'on')) {
        return false;
    }
    if ($name === 'href') {
        if ($element->localName === 'image') {
            return (bool)preg_match(SVG_INLINE_IMAGE, $value);
        }
        return (bool)preg_match('/^#[A-Za-z_][\w.:-]*$/', $value);
    }
    if ($namespace === SVG_XLINK) {
        return false;
    }
    $plain = strtolower((string)preg_replace('/[\s\x00-\x1f]+/', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5)));
    if (str_contains($plain, 'javascript:') || str_contains($plain, 'vbscript:') || str_contains($plain, 'data:')) {
        return false;
    }
    if ($name === 'style' || str_contains($plain, 'url(')) {
        return svg_css_safe($value);
    }
    return true;
}

/** CSS uvnitř SVG smí odkazovat jen na prvky ve stejném souboru (url(#id)). */
function svg_css_safe(string $css): bool
{
    $plain = strtolower((string)preg_replace('/\s+/', '', $css));
    if (str_contains($plain, '@import') || str_contains($plain, 'expression(') || str_contains($plain, 'javascript:') || str_contains($plain, '-moz-binding') || str_contains($plain, 'behavior:')) {
        return false;
    }
    preg_match_all('/url\(([^)]*)\)/', $plain, $matches);
    foreach ($matches[1] as $target) {
        if (!str_starts_with(trim($target, "'\""), '#')) {
            return false;
        }
    }
    return true;
}

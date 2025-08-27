<?php

namespace Feature;

use BadMethodCallException;
use DateTime;
use DOMDocument;
use DOMXPath;
use Exception;
use Icamys\SitemapGenerator\Config;
use Icamys\SitemapGenerator\Runtime;
use Icamys\SitemapGenerator\SitemapGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SimpleXMLElement;

class SitemapGeneratorTest extends TestCase
{
    public function testSingleSitemapWithDefaultValues()
    {
        $config = new Config();
        $config->setBaseURL('https://example.com');
        $config->setSaveDirectory(sys_get_temp_dir());

        $generator = new SitemapGenerator($config);
        $alternates = [
            ['hreflang' => 'de', 'href' => "http://www.example.com/de"],
            ['hreflang' => 'fr', 'href' => "http://www.example.com/fr"],
        ];

        $datetimeStr = '2020-12-29T08:46:55+00:00';
        $lastmod = new DateTime('2020-12-29T08:46:55+00:00');

        for ($i = 0; $i < 2; $i++) {
            $generator->addURL("/path/to/page-$i/", $lastmod, 'always', 0.5, $alternates);
        }

        $generator->flush();
        $generator->finalize();

        $sitemapFilepath = $config->getSaveDirectory() . DIRECTORY_SEPARATOR. 'sitemap.xml';
        $this->assertFileExists($sitemapFilepath);

        $sitemapXHTML = new SimpleXMLElement(file_get_contents($sitemapFilepath), 0, false, 'xhtml', true);
        foreach ($sitemapXHTML->children() as $url) {
            $links = $url->children('xhtml', true)->link;
            $this->assertEquals('alternate', $links[0]->attributes()['rel']);
            $this->assertEquals('de', $links[0]->attributes()['hreflang']);
            $this->assertEquals('http://www.example.com/de', $links[0]->attributes()['href']);
            $this->assertEquals('alternate', $links[1]->attributes()['rel']);
            $this->assertEquals('fr', $links[1]->attributes()['hreflang']);
            $this->assertEquals('http://www.example.com/fr', $links[1]->attributes()['href']);
        }

        $sitemap = new SimpleXMLElement(file_get_contents($sitemapFilepath));
        $this->assertEquals('urlset', $sitemap->getName());
        $this->assertEquals(2, $sitemap->count());

        $ns = $sitemap->getNamespaces();
        $this->assertEquals('http://www.w3.org/2001/XMLSchema-instance', $ns['xsi']);
        $this->assertEquals('http://www.sitemaps.org/schemas/sitemap/0.9', array_shift($ns));

        $this->assertEquals('https://example.com/path/to/page-0/', $sitemap->url[0]->loc);
        $this->assertEquals($datetimeStr, $sitemap->url[0]->lastmod);
        $this->assertEquals('always', $sitemap->url[0]->changefreq);
        $this->assertEquals('0.5', $sitemap->url[0]->priority);

        $this->assertEquals('https://example.com/path/to/page-1/', $sitemap->url[1]->loc);
        $this->assertEquals($datetimeStr, $sitemap->url[1]->lastmod);
        $this->assertEquals('always', $sitemap->url[1]->changefreq);
        $this->assertEquals('0.5', $sitemap->url[1]->priority);
        unlink($sitemapFilepath);

        $generatedFiles = $generator->getGeneratedFiles();
        $this->assertCount(2, $generatedFiles);
        $this->assertNotEmpty($generatedFiles['sitemaps_location']);
        $this->assertCount(1, $generatedFiles['sitemaps_location']);
        $this->assertEquals($sitemapFilepath, $generatedFiles['sitemaps_location'][0]);
        $this->assertEquals('https://example.com/sitemap.xml', $generatedFiles['sitemaps_index_url']);
    }

    public function testSitemapWithStylesheets()
    {
        $config = new Config();
        $config->setBaseURL('https://example.com');
        $config->setSaveDirectory(sys_get_temp_dir());

        $stylesheetUrl = "stylesheet.xsl";
        $lastmod = new DateTime('2020-12-29T08:46:55+00:00');

        $generator = new SitemapGenerator($config);
        $generator->setSitemapStylesheet($stylesheetUrl);
        $generator->addURL("/path/to/page-1/", $lastmod, 'always', 0.5);

        $generator->flush();
        $generator->finalize();

        $sitemapFilepath = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap.xml';
        $this->assertFileExists($sitemapFilepath);

        $stylesheetAttrs = $this->getXMLStylesheetAttributes($sitemapFilepath);
        $this->assertNotNull($stylesheetAttrs);
        $this->assertEquals('text/xsl', $stylesheetAttrs['type']);
        $this->assertEquals($stylesheetUrl, $stylesheetAttrs['href']);
        unlink($sitemapFilepath);
    }

    public function testSitemapWithoutStylesheets()
    {
        $config = new Config();
        $config->setBaseURL('https://example.com');
        $config->setSaveDirectory(sys_get_temp_dir());

        $lastmod = new DateTime('2020-12-29T08:46:55+00:00');

        $generator = new SitemapGenerator($config);
        $generator->addURL("/path/to/page-1/", $lastmod, 'always', 0.5);

        $generator->flush();
        $generator->finalize();

        $sitemapFilepath = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap.xml';
        $this->assertFileExists($sitemapFilepath);

        $stylesheetAttrs = $this->getXMLStylesheetAttributes($sitemapFilepath);
        $this->assertNull($stylesheetAttrs);
        unlink($sitemapFilepath);
    }

    /**
     * Retrieves the attributes of the <?xml-stylesheet?> element from an XML sitemap file.
     *
     * @param string $sitemapFilePath The path to the XML sitemap file.
     * @return array|null An associative array of attributes for the <?xml-stylesheet?> element, or null if not found.
     */
    private function getXMLStylesheetAttributes($sitemapFilePath)
    {
        $xml = new DOMDocument();
        $xml->load($sitemapFilePath);

        $xpath = new DOMXPath($xml);
        $stylesheetElement = $xpath->query('/processing-instruction("xml-stylesheet")')->item(0);

        if ($stylesheetElement) {
            $attributes = [];
            $data = $stylesheetElement->data;
            $data = trim(str_replace('?>', '', $data));

            $parts = explode(' ', $data);
            foreach ($parts as $part) {
                $attribute = explode('=', $part);
                $attributeName = trim($attribute[0]);
                $attributeValue = trim($attribute[1], '"\'');
                $attributes[$attributeName] = $attributeValue;
            }

            return $attributes;
        }

        return null;
    }

    public function testSingleSitemapWithCustomSitemapName()
    {
        $config = new Config();
        $config->setBaseURL('https://example.com');
        $config->setSaveDirectory(sys_get_temp_dir());

        $generator = new SitemapGenerator($config);
        $generator->setSitemapFilename('custom.xml');

        $alternates = [
            ['hreflang' => 'de', 'href' => "http://www.example.com/de"],
            ['hreflang' => 'fr', 'href' => "http://www.example.com/fr"],
        ];
        for ($i = 0; $i < 2; $i++) {
            $generator->addURL("/path/to/page-$i/", new DateTime(), 'always', 0.5, $alternates);
        }

        $generator->flush();
        $generator->finalize();

        $sitemapFilepath = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'custom.xml';
        $this->assertFileExists($sitemapFilepath);
        unlink($sitemapFilepath);

        $generatedFiles = $generator->getGeneratedFiles();
        $this->assertCount(2, $generatedFiles);
        $this->assertNotEmpty($generatedFiles['sitemaps_location']);
        $this->assertCount(1, $generatedFiles['sitemaps_location']);
        $this->assertEquals($sitemapFilepath, $generatedFiles['sitemaps_location'][0]);
        $this->assertEquals('https://example.com/custom.xml', $generatedFiles['sitemaps_index_url']);
    }

    public function testSingleSitemapWithExtendedSiteUrl()
    {
        $config = new Config();
        $config->setBaseURL('https://example.com/submodule/');
        $config->setSaveDirectory(sys_get_temp_dir());

        $generator = new SitemapGenerator($config);
        $alternates = [
            ['hreflang' => 'de', 'href' => "http://www.example.com/submodule/de"],
            ['hreflang' => 'fr', 'href' => "http://www.example.com/submodule/fr"],
        ];

        $datetimeStr = '2020-12-29T08:46:55+00:00';
        $lastmod = new DateTime('2020-12-29T08:46:55+00:00');

        for ($i = 0; $i < 2; $i++) {
            $generator->addURL("/path/to/page-$i/", $lastmod, 'always', 0.5, $alternates);
        }

        $generator->flush();
        $generator->finalize();
        $sitemapFilepath = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap.xml';

        $this->assertFileExists($sitemapFilepath);

        $sitemapXHTML = new SimpleXMLElement(file_get_contents($sitemapFilepath), 0, false, 'xhtml', true);
        foreach ($sitemapXHTML->children() as $url) {
            $links = $url->children('xhtml', true)->link;
            $this->assertEquals('alternate', $links[0]->attributes()['rel']);
            $this->assertEquals('de', $links[0]->attributes()['hreflang']);
            $this->assertEquals('http://www.example.com/submodule/de', $links[0]->attributes()['href']);
            $this->assertEquals('alternate', $links[1]->attributes()['rel']);
            $this->assertEquals('fr', $links[1]->attributes()['hreflang']);
            $this->assertEquals('http://www.example.com/submodule/fr', $links[1]->attributes()['href']);
        }

        $sitemap = new SimpleXMLElement(file_get_contents($sitemapFilepath));
        $this->assertEquals('urlset', $sitemap->getName());
        $this->assertEquals(2, $sitemap->count());

        $ns = $sitemap->getNamespaces();
        $this->assertEquals('http://www.w3.org/2001/XMLSchema-instance', $ns['xsi']);
        $this->assertEquals('http://www.sitemaps.org/schemas/sitemap/0.9', array_shift($ns));

        $this->assertEquals('https://example.com/submodule/path/to/page-0/', $sitemap->url[0]->loc);
        $this->assertEquals($datetimeStr, $sitemap->url[0]->lastmod);
        $this->assertEquals('always', $sitemap->url[0]->changefreq);
        $this->assertEquals('0.5', $sitemap->url[0]->priority);

        $this->assertEquals('https://example.com/submodule/path/to/page-1/', $sitemap->url[1]->loc);
        $this->assertEquals($datetimeStr, $sitemap->url[1]->lastmod);
        $this->assertEquals('always', $sitemap->url[1]->changefreq);
        $this->assertEquals('0.5', $sitemap->url[1]->priority);
        unlink($sitemapFilepath);

        $generatedFiles = $generator->getGeneratedFiles();
        $this->assertCount(2, $generatedFiles);
        $this->assertNotEmpty($generatedFiles['sitemaps_location']);
        $this->assertCount(1, $generatedFiles['sitemaps_location']);
        $this->assertEquals($sitemapFilepath, $generatedFiles['sitemaps_location'][0]);
        $this->assertEquals('https://example.com/submodule/sitemap.xml', $generatedFiles['sitemaps_index_url']);
    }

    public function testSingleSitemapWithEnabledCompression()
    {
        $config = new Config();
        $config->setBaseURL('https://example.com');
        $config->setSaveDirectory(sys_get_temp_dir());

        $generator = new SitemapGenerator($config);
        $generator->enableCompression();
        $alternates = [
            ['hreflang' => 'de', 'href' => "http://www.example.com/de"],
            ['hreflang' => 'fr', 'href' => "http://www.example.com/fr"],
        ];

        $datetimeStr = '2020-12-29T08:46:55+00:00';
        $lastmod = new DateTime('2020-12-29T08:46:55+00:00');

        for ($i = 0; $i < 2; $i++) {
            $generator->addURL("/path/to/page-$i/", $lastmod, 'always', 0.5, $alternates);
        }

        $generator->flush();
        $generator->finalize();

        $sitemapFilepath = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap.xml.gz';
        $sitemapFilepathUncompressed = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap.xml';
        $this->assertFileExists($sitemapFilepath);
        copy('compress.zlib://' . $sitemapFilepath, $sitemapFilepathUncompressed);

        $sitemapXHTML = new SimpleXMLElement(file_get_contents($sitemapFilepathUncompressed), 0, false, 'xhtml', true);
        foreach ($sitemapXHTML->children() as $url) {
            $links = $url->children('xhtml', true)->link;
            $this->assertEquals('alternate', $links[0]->attributes()['rel']);
            $this->assertEquals('de', $links[0]->attributes()['hreflang']);
            $this->assertEquals('http://www.example.com/de', $links[0]->attributes()['href']);
            $this->assertEquals('alternate', $links[1]->attributes()['rel']);
            $this->assertEquals('fr', $links[1]->attributes()['hreflang']);
            $this->assertEquals('http://www.example.com/fr', $links[1]->attributes()['href']);
        }

        $sitemap = new SimpleXMLElement(file_get_contents($sitemapFilepathUncompressed));
        $this->assertEquals('urlset', $sitemap->getName());
        $this->assertEquals(2, $sitemap->count());

        $ns = $sitemap->getNamespaces();
        $this->assertEquals('http://www.w3.org/2001/XMLSchema-instance', $ns['xsi']);
        $this->assertEquals('http://www.sitemaps.org/schemas/sitemap/0.9', array_shift($ns));

        $this->assertEquals('https://example.com/path/to/page-0/', $sitemap->url[0]->loc);
        $this->assertEquals($datetimeStr, $sitemap->url[0]->lastmod);
        $this->assertEquals('always', $sitemap->url[0]->changefreq);
        $this->assertEquals('0.5', $sitemap->url[0]->priority);

        $this->assertEquals('https://example.com/path/to/page-1/', $sitemap->url[1]->loc);
        $this->assertEquals($datetimeStr, $sitemap->url[1]->lastmod);
        $this->assertEquals('always', $sitemap->url[1]->changefreq);
        $this->assertEquals('0.5', $sitemap->url[1]->priority);
        unlink($sitemapFilepath);
        unlink($sitemapFilepathUncompressed);

        $generatedFiles = $generator->getGeneratedFiles();
        $this->assertCount(2, $generatedFiles);
        $this->assertNotEmpty($generatedFiles['sitemaps_location']);
        $this->assertCount(1, $generatedFiles['sitemaps_location']);
        $this->assertEquals($sitemapFilepath, $generatedFiles['sitemaps_location'][0]);
        $this->assertEquals('https://example.com/sitemap.xml.gz', $generatedFiles['sitemaps_index_url']);
    }

    public function testSingleSitemapWithEnabledCompressionAndCreatedRobots()
    {
        $config = new Config();
        $config->setBaseURL('https://example.com');
        $config->setSaveDirectory(sys_get_temp_dir());

        $generator = new SitemapGenerator($config);
        $generator->enableCompression();
        $alternates = [
            ['hreflang' => 'de', 'href' => "http://www.example.com/de"],
            ['hreflang' => 'fr', 'href' => "http://www.example.com/fr"],
        ];

        $datetimeStr = '2020-12-29T08:46:55+00:00';
        $lastmod = new DateTime('2020-12-29T08:46:55+00:00');

        for ($i = 0; $i < 2; $i++) {
            $generator->addURL("/path/to/page-$i/", $lastmod, 'always', 0.5, $alternates);
        }

        $generator->flush();
        $generator->finalize();

        $sitemapFilepath = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap.xml.gz';
        $sitemapFilepathUncompressed = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap.xml';
        $this->assertFileExists($sitemapFilepath);
        copy('compress.zlib://' . $sitemapFilepath, $sitemapFilepathUncompressed);

        $sitemapXHTML = new SimpleXMLElement(file_get_contents($sitemapFilepathUncompressed), 0, false, 'xhtml', true);
        foreach ($sitemapXHTML->children() as $url) {
            $links = $url->children('xhtml', true)->link;
            $this->assertEquals('alternate', $links[0]->attributes()['rel']);
            $this->assertEquals('de', $links[0]->attributes()['hreflang']);
            $this->assertEquals('http://www.example.com/de', $links[0]->attributes()['href']);
            $this->assertEquals('alternate', $links[1]->attributes()['rel']);
            $this->assertEquals('fr', $links[1]->attributes()['hreflang']);
            $this->assertEquals('http://www.example.com/fr', $links[1]->attributes()['href']);
        }

        $sitemap = new SimpleXMLElement(file_get_contents($sitemapFilepathUncompressed));
        $this->assertEquals('urlset', $sitemap->getName());
        $this->assertEquals(2, $sitemap->count());

        $ns = $sitemap->getNamespaces();
        $this->assertEquals('http://www.w3.org/2001/XMLSchema-instance', $ns['xsi']);
        $this->assertEquals('http://www.sitemaps.org/schemas/sitemap/0.9', array_shift($ns));

        $this->assertEquals('https://example.com/path/to/page-0/', $sitemap->url[0]->loc);
        $this->assertEquals($datetimeStr, $sitemap->url[0]->lastmod);
        $this->assertEquals('always', $sitemap->url[0]->changefreq);
        $this->assertEquals('0.5', $sitemap->url[0]->priority);

        $this->assertEquals('https://example.com/path/to/page-1/', $sitemap->url[1]->loc);
        $this->assertEquals($datetimeStr, $sitemap->url[1]->lastmod);
        $this->assertEquals('always', $sitemap->url[1]->changefreq);
        $this->assertEquals('0.5', $sitemap->url[1]->priority);
        unlink($sitemapFilepath);
        unlink($sitemapFilepathUncompressed);

        $generator->updateRobots();
        $robotsPath = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'robots.txt';
        $this->assertFileExists($robotsPath);
        $robotsContent = file_get_contents($robotsPath);
        $this->assertStringContainsString('Sitemap: https://example.com/sitemap.xml.gz', $robotsContent);
        unlink($robotsPath);

        $generatedFiles = $generator->getGeneratedFiles();
        $this->assertCount(2, $generatedFiles);
        $this->assertNotEmpty($generatedFiles['sitemaps_location']);
        $this->assertCount(1, $generatedFiles['sitemaps_location']);
        $this->assertEquals($config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap.xml.gz', $generatedFiles['sitemaps_location'][0]);
        $this->assertEquals('https://example.com/sitemap.xml.gz', $generatedFiles['sitemaps_index_url']);
    }

    public function testMultipleSitemapsWithDefaultValues()
    {
        $config = new Config();
        $config->setBaseURL('https://example.com');
        $config->setSaveDirectory(sys_get_temp_dir());

        $generator = new SitemapGenerator($config);
        $generator->setMaxURLsPerSitemap(1);
        $alternates = [
            ['hreflang' => 'de', 'href' => "http://www.example.com/de"],
            ['hreflang' => 'fr', 'href' => "http://www.example.com/fr"],
        ];

        $datetimeStr = '2020-12-29T08:46:55+00:00';
        $lastmod = new DateTime('2020-12-29T08:46:55+00:00');

        for ($i = 0; $i < 2; $i++) {
            $generator->addURL("/path/to/page-$i/", $lastmod, 'always', 0.5, $alternates);
        }

        $generator->flush();
        $generator->finalize();

        $sitemapIndexFilepath = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap-index.xml';
        $this->assertFileExists($sitemapIndexFilepath);
        $sitemapIndex = new SimpleXMLElement(file_get_contents($sitemapIndexFilepath));
        $this->assertEquals('sitemapindex', $sitemapIndex->getName());
        $this->assertEquals(2, $sitemapIndex->count());
        $ns = $sitemapIndex->getNamespaces();
        $this->assertEquals('http://www.w3.org/2001/XMLSchema-instance', $ns['xsi']);
        $this->assertEquals('http://www.sitemaps.org/schemas/sitemap/0.9', array_shift($ns));
        $this->assertEquals('https://example.com/sitemap1.xml', $sitemapIndex->sitemap[0]->loc);
        $this->assertNotNull($sitemapIndex->sitemap[0]->lastmod);
        $this->assertEquals('https://example.com/sitemap2.xml', $sitemapIndex->sitemap[1]->loc);
        $this->assertNotNull($sitemapIndex->sitemap[1]->lastmod);
        unlink($sitemapIndexFilepath);

        $sitemapFilepath1 = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap1.xml';
        $this->assertFileExists($sitemapFilepath1);

        $sitemapXHTML = new SimpleXMLElement(file_get_contents($sitemapFilepath1), 0, false, 'xhtml', true);
        foreach ($sitemapXHTML->children() as $url) {
            $links = $url->children('xhtml', true)->link;
            $this->assertEquals('alternate', $links[0]->attributes()['rel']);
            $this->assertEquals('de', $links[0]->attributes()['hreflang']);
            $this->assertEquals('http://www.example.com/de', $links[0]->attributes()['href']);
            $this->assertEquals('alternate', $links[1]->attributes()['rel']);
            $this->assertEquals('fr', $links[1]->attributes()['hreflang']);
            $this->assertEquals('http://www.example.com/fr', $links[1]->attributes()['href']);
        }

        $sitemap1 = new SimpleXMLElement(file_get_contents($sitemapFilepath1));
        $this->assertEquals('urlset', $sitemap1->getName());
        $this->assertEquals(1, $sitemap1->count());
        $ns = $sitemap1->getNamespaces();
        $this->assertEquals('http://www.w3.org/2001/XMLSchema-instance', $ns['xsi']);
        $this->assertEquals('http://www.sitemaps.org/schemas/sitemap/0.9', array_shift($ns));
        $this->assertEquals('https://example.com/path/to/page-0/', $sitemap1->url[0]->loc);
        $this->assertEquals($datetimeStr, $sitemap1->url[0]->lastmod);
        $this->assertEquals('always', $sitemap1->url[0]->changefreq);
        $this->assertEquals('0.5', $sitemap1->url[0]->priority);
        unlink($sitemapFilepath1);

        $sitemapFilepath2 = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap2.xml';
        $this->assertFileExists($sitemapFilepath2);

        $sitemapXHTML = new SimpleXMLElement(file_get_contents($sitemapFilepath2), 0, false, 'xhtml', true);
        foreach ($sitemapXHTML->children() as $url) {
            $links = $url->children('xhtml', true)->link;
            $this->assertEquals('alternate', $links[0]->attributes()['rel']);
            $this->assertEquals('de', $links[0]->attributes()['hreflang']);
            $this->assertEquals('http://www.example.com/de', $links[0]->attributes()['href']);
            $this->assertEquals('alternate', $links[1]->attributes()['rel']);
            $this->assertEquals('fr', $links[1]->attributes()['hreflang']);
            $this->assertEquals('http://www.example.com/fr', $links[1]->attributes()['href']);
        }

        $sitemap2 = new SimpleXMLElement(file_get_contents($sitemapFilepath2));
        $this->assertEquals('urlset', $sitemap2->getName());
        $this->assertEquals(1, $sitemap2->count());
        $ns = $sitemap2->getNamespaces();
        $this->assertEquals('http://www.w3.org/2001/XMLSchema-instance', $ns['xsi']);
        $this->assertEquals('http://www.sitemaps.org/schemas/sitemap/0.9', array_shift($ns));
        $this->assertEquals('https://example.com/path/to/page-1/', $sitemap2->url[0]->loc);
        $this->assertEquals($datetimeStr, $sitemap2->url[0]->lastmod);
        $this->assertEquals('always', $sitemap2->url[0]->changefreq);
        $this->assertEquals('0.5', $sitemap2->url[0]->priority);
        unlink($sitemapFilepath2);

        $generatedFiles = $generator->getGeneratedFiles();
        $this->assertCount(3, $generatedFiles);
        $this->assertNotEmpty($generatedFiles['sitemaps_location']);
        $this->assertCount(2, $generatedFiles['sitemaps_location']);
        $this->assertEquals($config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap1.xml', $generatedFiles['sitemaps_location'][0]);
        $this->assertEquals($config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap2.xml', $generatedFiles['sitemaps_location'][1]);
        $this->assertEquals($config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap-index.xml', $generatedFiles['sitemaps_index_location']);
        $this->assertEquals('https://example.com/sitemap-index.xml', $generatedFiles['sitemaps_index_url']);
    }

    public function testMultipleSitemapsWithCustomSitemapIndexName()
    {
        $config = new Config();
        $config->setBaseURL('https://example.com');
        $config->setSaveDirectory(sys_get_temp_dir());

        $generator = new SitemapGenerator($config);
        $generator->setSitemapIndexFilename('custom-index.xml');
        $generator->setMaxURLsPerSitemap(1);
        $alternates = [
            ['hreflang' => 'de', 'href' => "http://www.example.com/de"],
            ['hreflang' => 'fr', 'href' => "http://www.example.com/fr"],
        ];

        $lastmod = new DateTime('2020-12-29T08:46:55+00:00');

        for ($i = 0; $i < 2; $i++) {
            $generator->addURL("/path/to/page-$i/", $lastmod, 'always', 0.5, $alternates);
        }

        $generator->flush();
        $generator->finalize();

        $sitemapIndexFilepath = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'custom-index.xml';
        $this->assertFileExists($sitemapIndexFilepath);
        unlink($sitemapIndexFilepath);

        $sitemapFilepath1 = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap1.xml';
        $this->assertFileExists($sitemapFilepath1);
        unlink($sitemapFilepath1);

        $sitemapFilepath2 = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap2.xml';
        $this->assertFileExists($sitemapFilepath2);
        unlink($sitemapFilepath2);

        $generatedFiles = $generator->getGeneratedFiles();
        $this->assertCount(3, $generatedFiles);
        $this->assertNotEmpty($generatedFiles['sitemaps_location']);
        $this->assertCount(2, $generatedFiles['sitemaps_location']);
        $this->assertEquals($config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap1.xml', $generatedFiles['sitemaps_location'][0]);
        $this->assertEquals($config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap2.xml', $generatedFiles['sitemaps_location'][1]);
        $this->assertEquals($config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'custom-index.xml', $generatedFiles['sitemaps_index_location']);
        $this->assertEquals('https://example.com/custom-index.xml', $generatedFiles['sitemaps_index_url']);
    }

    public function testMultipleSitemapsCompressionAndCreatedRobots()
    {
        $config = new Config();
        $config->setBaseURL('https://example.com');
        $config->setSaveDirectory(sys_get_temp_dir());

        $generator = new SitemapGenerator($config);
        $generator->setMaxURLsPerSitemap(1);
        $generator->enableCompression();
        $alternates = [
            ['hreflang' => 'de', 'href' => "http://www.example.com/de"],
            ['hreflang' => 'fr', 'href' => "http://www.example.com/fr"],
        ];

        $datetimeStr = '2020-12-29T08:46:55+00:00';
        $lastmod = new DateTime('2020-12-29T08:46:55+00:00');

        for ($i = 0; $i < 2; $i++) {
            $generator->addURL("/path/to/page-$i/", $lastmod, 'always', 0.5, $alternates);
        }

        $generator->flush();
        $generator->finalize();

        $sitemapIndexFilepath = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap-index.xml';
        $this->assertFileExists($sitemapIndexFilepath);
        $sitemapIndex = new SimpleXMLElement(file_get_contents($sitemapIndexFilepath));
        $this->assertEquals('sitemapindex', $sitemapIndex->getName());
        $this->assertEquals(2, $sitemapIndex->count());
        $ns = $sitemapIndex->getNamespaces();
        $this->assertEquals('http://www.w3.org/2001/XMLSchema-instance', $ns['xsi']);
        $this->assertEquals('http://www.sitemaps.org/schemas/sitemap/0.9', array_shift($ns));
        $this->assertEquals('https://example.com/sitemap1.xml.gz', $sitemapIndex->sitemap[0]->loc);
        $this->assertNotNull($sitemapIndex->sitemap[0]->lastmod);
        $this->assertEquals('https://example.com/sitemap2.xml.gz', $sitemapIndex->sitemap[1]->loc);
        $this->assertNotNull($sitemapIndex->sitemap[1]->lastmod);
        unlink($sitemapIndexFilepath);

        $sitemapFilepath1 = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap1.xml';
        $sitemapFilepath1Compressed = $sitemapFilepath1 . '.gz';
        $this->assertFileExists($sitemapFilepath1Compressed);
        copy('compress.zlib://' . $sitemapFilepath1Compressed, $sitemapFilepath1);

        $sitemapXHTML = new SimpleXMLElement(file_get_contents($sitemapFilepath1), 0, false, 'xhtml', true);
        foreach ($sitemapXHTML->children() as $url) {
            $links = $url->children('xhtml', true)->link;
            $this->assertEquals('alternate', $links[0]->attributes()['rel']);
            $this->assertEquals('de', $links[0]->attributes()['hreflang']);
            $this->assertEquals('http://www.example.com/de', $links[0]->attributes()['href']);
            $this->assertEquals('alternate', $links[1]->attributes()['rel']);
            $this->assertEquals('fr', $links[1]->attributes()['hreflang']);
            $this->assertEquals('http://www.example.com/fr', $links[1]->attributes()['href']);
        }

        $sitemap1 = new SimpleXMLElement(file_get_contents($sitemapFilepath1));
        $this->assertEquals('urlset', $sitemap1->getName());
        $this->assertEquals(1, $sitemap1->count());
        $ns = $sitemap1->getNamespaces();
        $this->assertEquals('http://www.w3.org/2001/XMLSchema-instance', $ns['xsi']);
        $this->assertEquals('http://www.sitemaps.org/schemas/sitemap/0.9', array_shift($ns));
        $this->assertEquals('https://example.com/path/to/page-0/', $sitemap1->url[0]->loc);
        $this->assertEquals($datetimeStr, $sitemap1->url[0]->lastmod);
        $this->assertEquals('always', $sitemap1->url[0]->changefreq);
        $this->assertEquals('0.5', $sitemap1->url[0]->priority);
        unlink($sitemapFilepath1);
        unlink($sitemapFilepath1Compressed);

        $sitemapFilepath2 = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap2.xml';
        $sitemapFilepath2Compressed = $sitemapFilepath2 . '.gz';
        $this->assertFileExists($sitemapFilepath2Compressed);
        copy('compress.zlib://' . $sitemapFilepath2Compressed, $sitemapFilepath2);
        $this->assertFileExists($sitemapFilepath2);

        $sitemapXHTML = new SimpleXMLElement(file_get_contents($sitemapFilepath2), 0, false, 'xhtml', true);
        foreach ($sitemapXHTML->children() as $url) {
            $links = $url->children('xhtml', true)->link;
            $this->assertEquals('alternate', $links[0]->attributes()['rel']);
            $this->assertEquals('de', $links[0]->attributes()['hreflang']);
            $this->assertEquals('http://www.example.com/de', $links[0]->attributes()['href']);
            $this->assertEquals('alternate', $links[1]->attributes()['rel']);
            $this->assertEquals('fr', $links[1]->attributes()['hreflang']);
            $this->assertEquals('http://www.example.com/fr', $links[1]->attributes()['href']);
        }

        $sitemap2 = new SimpleXMLElement(file_get_contents($sitemapFilepath2));
        $this->assertEquals('urlset', $sitemap2->getName());
        $this->assertEquals(1, $sitemap2->count());
        $ns = $sitemap2->getNamespaces();
        $this->assertEquals('http://www.w3.org/2001/XMLSchema-instance', $ns['xsi']);
        $this->assertEquals('http://www.sitemaps.org/schemas/sitemap/0.9', array_shift($ns));
        $this->assertEquals('https://example.com/path/to/page-1/', $sitemap2->url[0]->loc);
        $this->assertEquals($datetimeStr, $sitemap2->url[0]->lastmod);
        $this->assertEquals('always', $sitemap2->url[0]->changefreq);
        $this->assertEquals('0.5', $sitemap2->url[0]->priority);
        unlink($sitemapFilepath2);
        unlink($sitemapFilepath2Compressed);

        $generator->updateRobots();
        $robotsPath = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'robots.txt';
        $this->assertFileExists($robotsPath);
        $robotsContent = file_get_contents($robotsPath);
        $this->assertStringContainsString('Sitemap: https://example.com/sitemap-index.xml', $robotsContent);
        unlink($robotsPath);

        $generatedFiles = $generator->getGeneratedFiles();
        $this->assertCount(3, $generatedFiles);
        $this->assertNotEmpty($generatedFiles['sitemaps_location']);
        $this->assertCount(2, $generatedFiles['sitemaps_location']);
        $this->assertEquals($config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap1.xml.gz', $generatedFiles['sitemaps_location'][0]);
        $this->assertEquals($config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap2.xml.gz', $generatedFiles['sitemaps_location'][1]);
        $this->assertEquals($config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap-index.xml', $generatedFiles['sitemaps_index_location']);
        $this->assertEquals('https://example.com/sitemap-index.xml', $generatedFiles['sitemaps_index_url']);
    }

    public function testSubmitValues()
    {
        $submitUrls = [
            'https://webmaster.yandex.ru/ping?sitemap=https://example.com/sitemap.xml',
        ];
        $consecutiveCallUrls = [];
        foreach ($submitUrls as $url) {
            $consecutiveCallUrls[] = [$this->equalTo($url)];
        }
        $curlHandle = curl_init();
        $runtimeMock = $this->createMock(Runtime::class);
        $runtimeMock
            ->expects($this->exactly(1))
            ->method('is_writable')
            ->willReturn(true);
        $runtimeMock
            ->expects($this->exactly(1))
            ->method('extension_loaded')
            ->with('curl')
            ->willReturn(true);
        $callIndex = 0;
        $runtimeMock
            ->expects($this->exactly(count($consecutiveCallUrls)))
            ->method('curl_init')
            ->with($this->callback(function ($url) use ($submitUrls, &$callIndex) {
                $expected = $submitUrls[$callIndex] ?? null;
                $this->assertSame($expected, $url, "curl_init called with unexpected URL at call #{$callIndex}");
                $callIndex++;
                return true;
            }))
            ->willReturn($curlHandle);
        $runtimeMock
            ->expects($this->exactly(count($consecutiveCallUrls)))
            ->method('curl_getinfo')
            ->willReturn(['http_code' => 200]);
        $runtimeMock
            ->expects($this->exactly(count($consecutiveCallUrls)))
            ->method('curl_setopt')
            ->willReturn(true);
        $runtimeMock
            ->expects($this->exactly(count($consecutiveCallUrls)))
            ->method('curl_exec')
            ->willReturn(true);

        $config = new Config();
        $config->setBaseURL('https://example.com');
        $config->setSaveDirectory(sys_get_temp_dir());
        $config->setRuntime($runtimeMock);

        $generator = new SitemapGenerator($config);
        $alternates = [
            ['hreflang' => 'de', 'href' => "http://www.example.com/de"],
            ['hreflang' => 'fr', 'href' => "http://www.example.com/fr"],
        ];

        $lastmod = new DateTime('2020-12-29T08:46:55+00:00');

        for ($i = 0; $i < 2; $i++) {
            $generator->addURL("/path/to/page-$i/", $lastmod, 'always', 0.5, $alternates);
        }

        $generator->flush();
        $generator->finalize();

        $sitemapFilepath = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap.xml';
        $this->assertFileExists($sitemapFilepath);
        unlink($sitemapFilepath);

        $generatedFiles = $generator->getGeneratedFiles();
        $this->assertCount(2, $generatedFiles);
        $this->assertNotEmpty($generatedFiles['sitemaps_location']);
        $this->assertCount(1, $generatedFiles['sitemaps_location']);
        $this->assertEquals($config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap.xml', $generatedFiles['sitemaps_location'][0]);
        $this->assertEquals('https://example.com/sitemap.xml', $generatedFiles['sitemaps_index_url']);

        $generator->submitSitemap('');
    }

    public function testExceptionWhenCurlIsNotPresent()
    {
        $this->expectException(BadMethodCallException::class);

        $runtimeMock = $this->createMock(Runtime::class);
        $runtimeMock
            ->expects($this->exactly(1))
            ->method('extension_loaded')
            ->with('curl')
            ->willReturn(false);
        $runtimeMock
            ->expects($this->exactly(1))
            ->method('is_writable')
            ->willReturn(true);

        $config = new Config();
        $config->setBaseURL('https://example.com');
        $config->setSaveDirectory(sys_get_temp_dir());
        $config->setRuntime($runtimeMock);

        $generator = new SitemapGenerator($config);
        $alternates = [
            ['hreflang' => 'de', 'href' => "http://www.example.com/de"],
            ['hreflang' => 'fr', 'href' => "http://www.example.com/fr"],
        ];

        $lastmod = new DateTime('2020-12-29T08:46:55+00:00');

        for ($i = 0; $i < 2; $i++) {
            $generator->addURL("/path/to/page-$i/", $lastmod, 'always', 0.5, $alternates);
        }

        $generator->flush();
        $generator->finalize();

        $sitemapFilepath = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap.xml';
        $this->assertFileExists($sitemapFilepath);
        unlink($sitemapFilepath);

        $generatedFiles = $generator->getGeneratedFiles();
        $this->assertCount(2, $generatedFiles);
        $this->assertNotEmpty($generatedFiles['sitemaps_location']);
        $this->assertCount(1, $generatedFiles['sitemaps_location']);
        $this->assertEquals($config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap.xml', $generatedFiles['sitemaps_location'][0]);
        $this->assertEquals('https://example.com/sitemap.xml', $generatedFiles['sitemaps_index_url']);

        $generator->submitSitemap('');
    }

    public function testGoogleVideoExtension()
    {
        $config = new Config();
        $config->setBaseURL('https://example.com');
        $config->setSaveDirectory(sys_get_temp_dir());

        $generator = new SitemapGenerator($config);

        $extensions = [
            'google_video' => [
                'thumbnail_loc' => 'http://www.example.com/thumbs/123.jpg',
                'title' => 'Grilling steaks for summer',
                'description' => 'Alkis shows you how to get perfectly done steaks every time',
                'content_loc' => 'http://streamserver.example.com/video123.mp4',
                'player_loc' => 'http://www.example.com/videoplayer.php?video=123',
                'duration' => 600,
                'expiration_date' => '2021-11-05T19:20:30+08:00',
                'rating' => 4.2,
                'view_count' => 12345,
                'publication_date' => '2007-11-05T19:20:30+08:00',
                'family_friendly' => 'yes',
                'restriction' => [
                    'relationship' => 'allow',
                    'value' => 'IE GB US CA',
                ],
                'platform' => [
                    'relationship' => 'allow',
                    'value' => 'web mobile',
                ],
                'price' => [
                    [
                        'currency' => 'EUR',
                        'value' => 1.99,
                        'type' => 'rent',
                        'resolution' => 'hd',
                    ]
                ],
                'requires_subscription' => 'yes',
                'uploader' => [
                    'info' => 'https://example.com/users/grillymcgrillerson',
                    'value' => 'GrillyMcGrillerson',
                ],
                'live' => 'no',
                'tag' => [
                    "steak", "meat", "summer", "outdoor"
                ],
                'category' => 'baking',
            ]
        ];

        $generator->addURL("/path/to/page/", null, null, null, null, $extensions);

        $generator->flush();
        $generator->finalize();

        $sitemapFilepath = $config->getSaveDirectory() . '/sitemap.xml';
        $this->assertFileExists($sitemapFilepath);

        $sitemap = new SimpleXMLElement(file_get_contents($sitemapFilepath), 0, false, 'video', true);
        $video = $sitemap->children()[0]->children('video', true)->video;
        $this->assertEquals('http://www.example.com/thumbs/123.jpg', $video->thumbnail_loc);
        $this->assertEquals('Grilling steaks for summer', $video->title);
        $this->assertEquals('Alkis shows you how to get perfectly done steaks every time', $video->description);
        $this->assertCount(2, $video->content_loc);
        $this->assertEquals('http://streamserver.example.com/video123.mp4', $video->content_loc[0]);
        $this->assertEquals('http://www.example.com/videoplayer.php?video=123', $video->content_loc[1]);
        $this->assertEquals('600', $video->duration);
        $this->assertEquals('2021-11-05T19:20:30+08:00', $video->expiration_date);
        $this->assertEquals('4.2', $video->rating);
        $this->assertEquals('12345', $video->view_count);
        $this->assertEquals('2007-11-05T19:20:30+08:00', $video->publication_date);
        $this->assertEquals('yes', $video->family_friendly);
        $this->assertEquals('IE GB US CA', $video->restriction);
        $this->assertEquals('web mobile', $video->platform);
        $this->assertEquals('1.99', $video->price);
        $this->assertEquals('yes', $video->requires_subscription);
        $this->assertEquals('GrillyMcGrillerson', $video->uploader);
        $this->assertEquals('no', $video->live);
        $this->assertCount(4, $video->tag);
        $this->assertEquals('steak', $video->tag[0]);
        $this->assertEquals('meat', $video->tag[1]);
        $this->assertEquals('summer', $video->tag[2]);
        $this->assertEquals('outdoor', $video->tag[3]);
        $this->assertEquals('baking', $video->category);
    }

    public function testGoogleVideoExtension_ValidationErrorOnUrlAdd()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required fields: thumbnail_loc, title, description');

        $config = new Config();
        $config->setBaseURL('https://example.com');
        $config->setSaveDirectory(sys_get_temp_dir());

        $generator = new SitemapGenerator($config);
        $extensions = ['google_video' => []];
        $generator->addURL("/path/to/page/", null, null, null, null, $extensions);
        $generator->flush();
        $generator->finalize();
    }

    public function testGoogleImageExtension_WithASingleImage()
    {
        $config = new Config();
        $config->setBaseURL('https://example.com');
        $config->setSaveDirectory(sys_get_temp_dir());

        $generator = new SitemapGenerator($config);

        $extensions = [
            'google_image' => [
                'loc' => 'https://www.example.com/thumbs/123.jpg',
                'title' => 'Cat vs Cabbage',
                'caption' => 'A funny picture of a cat eating cabbage',
                'geo_location' => 'Lyon, France',
                'license' => 'https://example.com/image-license',
            ]
        ];

        $generator->addURL("/path/to/page/", null, null, null, null, $extensions);

        $generator->flush();
        $generator->finalize();

        $sitemapFilepath = $config->getSaveDirectory() . '/sitemap.xml';
        $this->assertFileExists($sitemapFilepath);

        $sitemap = new SimpleXMLElement(file_get_contents($sitemapFilepath), 0, false, 'image', true);

        $image = $sitemap->children()[0]->children('image', true)->image[0];
        $this->assertEquals($extensions['google_image']['loc'], $image->loc);
        $this->assertEquals($extensions['google_image']['title'], $image->title);
        $this->assertEquals($extensions['google_image']['caption'], $image->caption);
        $this->assertEquals($extensions['google_image']['geo_location'], $image->geo_location);
        $this->assertEquals($extensions['google_image']['license'], $image->license);
    }

    public function testGoogleImageExtension_WithMultipleImages()
    {
        $config = new Config();
        $config->setBaseURL('https://example.com');
        $config->setSaveDirectory(sys_get_temp_dir());

        $generator = new SitemapGenerator($config);

        $extensions = [
            'google_image' => [
                [
                    'loc' => 'https://www.example.com/thumbs/123.jpg',
                    'title' => 'Cat vs Cabbage',
                    'caption' => 'A funny picture of a cat eating cabbage',
                    'geo_location' => 'Lyon, France',
                    'license' => 'https://example.com/image-license',
                ],
                [
                    'loc' => 'https://www.example.com/thumbs/456.jpg',
                    'title' => 'Dog vs Carrot',
                    'caption' => 'A funny picture of a dog eating carrot',
                    'geo_location' => 'Lyon, France',
                    'license' => 'https://example.com/image-license',
                ]
            ]
        ];

        $generator->addURL("/path/to/page/", null, null, null, null, $extensions);

        $generator->flush();
        $generator->finalize();

        $sitemapFilepath = $config->getSaveDirectory() . '/sitemap.xml';
        $this->assertFileExists($sitemapFilepath);

        $sitemap = new SimpleXMLElement(file_get_contents($sitemapFilepath), 0, false, 'image', true);

        $imageOne = $sitemap->children()[0]->children('image', true)->image[0];
        $this->assertEquals($extensions['google_image'][0]['loc'], $imageOne->loc);
        $this->assertEquals($extensions['google_image'][0]['title'], $imageOne->title);
        $this->assertEquals($extensions['google_image'][0]['caption'], $imageOne->caption);
        $this->assertEquals($extensions['google_image'][0]['geo_location'], $imageOne->geo_location);
        $this->assertEquals($extensions['google_image'][0]['license'], $imageOne->license);

        $imageTwo = $sitemap->children()[0]->children('image', true)->image[1];
        $this->assertEquals($extensions['google_image'][1]['loc'], $imageTwo->loc);
        $this->assertEquals($extensions['google_image'][1]['title'], $imageTwo->title);
        $this->assertEquals($extensions['google_image'][1]['caption'], $imageTwo->caption);
        $this->assertEquals($extensions['google_image'][1]['geo_location'], $imageTwo->geo_location);
        $this->assertEquals($extensions['google_image'][1]['license'], $imageTwo->license);
    }

    public function testGoogleImageExtension_WithTooManyImages()
    {
        $this->expectExceptionMessage('Too many images for a single URL. Maximum number of images allowed per page is 1000, got 1001. For more information, see https://www.google.com/schemas/sitemap-image/1.1/sitemap-image.xsd');

        $config = new Config();
        $config->setBaseURL('https://example.com');
        $config->setSaveDirectory(sys_get_temp_dir());

        $generator = new SitemapGenerator($config);

        $extensions = [
            'google_image' => []
        ];

        for ($i = 0; $i < 1001; $i++) {
            $extensions['google_image'][] = [
                'loc' => 'https://www.example.com/thumbs/123.jpg',
            ];
        }

        $generator->addURL("/path/to/page/", null, null, null, null, $extensions);
    }

    public function testGoogleImageExtension_ValidationErrorOnUrlAdd()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required fields: loc');

        $config = new Config();
        $config->setBaseURL('https://example.com');
        $config->setSaveDirectory(sys_get_temp_dir());

        $generator = new SitemapGenerator($config);
        $extensions = ['google_image' => ['foo' => 'bar']];
        $generator->addURL("/path/to/page/", null, null, null, null, $extensions);
        $generator->flush();
        $generator->finalize();
    }

    public function testMultipleSitemapsWithSitemapBaseUrl()
    {
        $config = new Config();
        $config->setBaseURL('https://example.com');
        $config->setSaveDirectory(sys_get_temp_dir());
        $config->setSitemapIndexURL('https://example.com/sitemaps/');

        $generator = new SitemapGenerator($config);
        $generator->setMaxURLsPerSitemap(1);
        $alternates = [
            ['hreflang' => 'de', 'href' => "http://www.example.com/de"],
            ['hreflang' => 'fr', 'href' => "http://www.example.com/fr"],
        ];

        $datetimeStr = '2020-12-29T08:46:55+00:00';
        $lastmod = new DateTime($datetimeStr);

        for ($i = 0; $i < 2; $i++) {
            $generator->addURL("/path/to/page-$i/", $lastmod, 'always', 0.5, $alternates);
        }

        $generator->flush();
        $generator->finalize();

        $sitemapIndexFilepath = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap-index.xml';
        $this->assertFileExists($sitemapIndexFilepath);
        $sitemapIndex = new SimpleXMLElement(file_get_contents($sitemapIndexFilepath));
        $this->assertEquals('sitemapindex', $sitemapIndex->getName());
        $this->assertEquals(2, $sitemapIndex->count());
        $ns = $sitemapIndex->getNamespaces();
        $this->assertEquals('http://www.w3.org/2001/XMLSchema-instance', $ns['xsi']);
        $this->assertEquals('http://www.sitemaps.org/schemas/sitemap/0.9', array_shift($ns));
        $this->assertEquals('https://example.com/sitemaps/sitemap1.xml', $sitemapIndex->sitemap[0]->loc);
        $this->assertNotNull($sitemapIndex->sitemap[0]->lastmod);
        $this->assertEquals('https://example.com/sitemaps/sitemap2.xml', $sitemapIndex->sitemap[1]->loc);
        $this->assertNotNull($sitemapIndex->sitemap[1]->lastmod);
        unlink($sitemapIndexFilepath);

        $sitemapFilepath1 = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap1.xml';
        $this->assertFileExists($sitemapFilepath1);

        $sitemapXHTML = new SimpleXMLElement(file_get_contents($sitemapFilepath1), 0, false, 'xhtml', true);
        foreach ($sitemapXHTML->children() as $url) {
            $links = $url->children('xhtml', true)->link;
            $this->assertEquals('alternate', $links[0]->attributes()['rel']);
            $this->assertEquals('de', $links[0]->attributes()['hreflang']);
            $this->assertEquals('http://www.example.com/de', $links[0]->attributes()['href']);
            $this->assertEquals('alternate', $links[1]->attributes()['rel']);
            $this->assertEquals('fr', $links[1]->attributes()['hreflang']);
            $this->assertEquals('http://www.example.com/fr', $links[1]->attributes()['href']);
        }

        $sitemap1 = new SimpleXMLElement(file_get_contents($sitemapFilepath1));
        $this->assertEquals('urlset', $sitemap1->getName());
        $this->assertEquals(1, $sitemap1->count());
        $ns = $sitemap1->getNamespaces();
        $this->assertEquals('http://www.w3.org/2001/XMLSchema-instance', $ns['xsi']);
        $this->assertEquals('http://www.sitemaps.org/schemas/sitemap/0.9', array_shift($ns));
        $this->assertEquals('https://example.com/path/to/page-0/', $sitemap1->url[0]->loc);
        $this->assertEquals($datetimeStr, $sitemap1->url[0]->lastmod);
        $this->assertEquals('always', $sitemap1->url[0]->changefreq);
        $this->assertEquals('0.5', $sitemap1->url[0]->priority);
        unlink($sitemapFilepath1);

        $sitemapFilepath2 = $config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap2.xml';
        $this->assertFileExists($sitemapFilepath2);

        $sitemapXHTML = new SimpleXMLElement(file_get_contents($sitemapFilepath2), 0, false, 'xhtml', true);
        foreach ($sitemapXHTML->children() as $url) {
            $links = $url->children('xhtml', true)->link;
            $this->assertEquals('alternate', $links[0]->attributes()['rel']);
            $this->assertEquals('de', $links[0]->attributes()['hreflang']);
            $this->assertEquals('http://www.example.com/de', $links[0]->attributes()['href']);
            $this->assertEquals('alternate', $links[1]->attributes()['rel']);
            $this->assertEquals('fr', $links[1]->attributes()['hreflang']);
            $this->assertEquals('http://www.example.com/fr', $links[1]->attributes()['href']);
        }

        $sitemap2 = new SimpleXMLElement(file_get_contents($sitemapFilepath2));
        $this->assertEquals('urlset', $sitemap2->getName());
        $this->assertEquals(1, $sitemap2->count());
        $ns = $sitemap2->getNamespaces();
        $this->assertEquals('http://www.w3.org/2001/XMLSchema-instance', $ns['xsi']);
        $this->assertEquals('http://www.sitemaps.org/schemas/sitemap/0.9', array_shift($ns));
        $this->assertEquals('https://example.com/path/to/page-1/', $sitemap2->url[0]->loc);
        $this->assertEquals($datetimeStr, $sitemap2->url[0]->lastmod);
        $this->assertEquals('always', $sitemap2->url[0]->changefreq);
        $this->assertEquals('0.5', $sitemap2->url[0]->priority);
        unlink($sitemapFilepath2);

        $generatedFiles = $generator->getGeneratedFiles();
        $this->assertCount(3, $generatedFiles);
        $this->assertNotEmpty($generatedFiles['sitemaps_location']);
        $this->assertCount(2, $generatedFiles['sitemaps_location']);
        $this->assertEquals($config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap1.xml', $generatedFiles['sitemaps_location'][0]);
        $this->assertEquals($config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap2.xml', $generatedFiles['sitemaps_location'][1]);
        $this->assertEquals($config->getSaveDirectory() . DIRECTORY_SEPARATOR . 'sitemap-index.xml', $generatedFiles['sitemaps_index_location']);
        $this->assertEquals('https://example.com/sitemaps/sitemap-index.xml', $generatedFiles['sitemaps_index_url']);
    }

    public static function URLsWithHTMLSpecialCharsData(): array {
        return [
            'ampersand' => [
                'https://example.com/index.php?param1=p1&param2=p2',
                'https://example.com/index.php?param1=p1&amp;param2=p2',
            ],
            'double quotes' => [
                'https://example.com/index.php?param1="p 1"&param2="p 2"',
                'https://example.com/index.php?param1=&quot;p 1&quot;&amp;param2=&quot;p 2&quot;',
            ],
            'single quotes' => [
                "https://example.com/index.php?param1='p 1'&param2='p 2'",
                'https://example.com/index.php?param1=&#039;p 1&#039;&amp;param2=&#039;p 2&#039;',
            ],
            'greater than and less than' => [
                'https://example.com/index.php?param1=<p 1>&param2=<p 2>',
                'https://example.com/index.php?param1=&lt;p 1&gt;&amp;param2=&lt;p 2&gt;',
            ],
            'non-ascii characters' => [
                'https://example.com/ümlat.php&q=name',
                'https://example.com/%C3%BCmlat.php&amp;q=name',
            ],
            'non-ascii characters - cyrillic' => [
                'https://example.com/кириллица.php&q=name',
                'https://example.com/%D0%BA%D0%B8%D1%80%D0%B8%D0%BB%D0%BB%D0%B8%D1%86%D0%B0.php&amp;q=name',
            ],
            'non-ascii characters - korean' => [
                'https://example.com/Hello/세상아/세상아-안녕',
                'https://example.com/Hello/%EC%84%B8%EC%83%81%EC%95%84/%EC%84%B8%EC%83%81%EC%95%84-%EC%95%88%EB%85%95',
            ],
            'non-ascii characters - japanese' => [
                'https://example.com/Hello/世界/こんにちは、世界',
                'https://example.com/Hello/%E4%B8%96%E7%95%8C/%E3%81%93%E3%82%93%E3%81%AB%E3%81%A1%E3%81%AF%E3%80%81%E4%B8%96%E7%95%8C',
            ],
            'non-ascii characters - chinese' => [
                'https://example.com/Hello/世界/你好，世界',
                'https://example.com/Hello/%E4%B8%96%E7%95%8C/%E4%BD%A0%E5%A5%BD%EF%BC%8C%E4%B8%96%E7%95%8C',
            ],
            'non-ascii characters - czech' => [
                'https://example.com/Hello/Světe/Ahoj-Světe',
                'https://example.com/Hello/Sv%C4%9Bte/Ahoj-Sv%C4%9Bte',
            ],
        ];
    }

    #[DataProvider('URLsWithHTMLSpecialCharsData')]
    public function testEncodeEscapeURL(string $inputPath, string $expectedURL)
    {
        $config = new Config();
        $config->setBaseURL('https://example.com');
        $config->setSaveDirectory(sys_get_temp_dir());

        $class = new ReflectionClass('Icamys\SitemapGenerator\SitemapGenerator');
        $method = $class->getMethod('encodeEscapeURL');
        $method->setAccessible(true);
        $obj = new SitemapGenerator($config);
        try {
            $gotURL = $method->invokeArgs($obj, array($inputPath));
        } catch (Exception $e) {
            $this->fail('Exception thrown: ' . $e->getMessage());
        }
        $this->assertEquals($expectedURL, $gotURL);
    }
}

<?php

namespace Tests\Wallabag\CoreBundle\Helper;

use PHPUnit\Framework\TestCase;
use Wallabag\CoreBundle\Helper\PdfContentFormatter;

class PdfContentFormatterTest extends TestCase
{
    public function testFormatsOneWordPerLinePdfText()
    {
        $formatter = new PdfContentFormatter();

        $content = "Introduction<br />\n<br />\nOn<br />\nOctober<br />\n5,<br />\n1960,<br />\nthe<br />\nAmerican<br />\nBallistic<br />\nMissile<br />\nEarly-Warning<br />\nSystem<br />\nstation<br />\nat<br />\nThule,<br />\nGreenland,<br />\nindicated<br />\na<br />\nlarge<br />\ncontingent<br />\nof<br />\nSoviet<br />\nmissiles<br />\nheaded<br />\ntowards<br />\nthe<br />\nUnited<br />\nStates<br />\n.<br />";

        $this->assertSame(
            '<p>Introduction</p><p>On October 5, 1960, the American Ballistic Missile Early-Warning System station at Thule, Greenland, indicated a large contingent of Soviet missiles headed towards the United States.</p>',
            $formatter->format($content, 'application/pdf')
        );
    }

    public function testFormatsHardWrappedPdfText()
    {
        $formatter = new PdfContentFormatter();

        $content = "Let me say that it is an extraordinary honor to be here <br />\ntonight, and a pleasure. I am a little frightened of you all, <br />\nbecause I am sure there are people here who know every <br />\nfield of knowledge that I have touched much better than I <br />\nknow it.";

        $this->assertSame(
            '<p>Let me say that it is an extraordinary honor to be here tonight, and a pleasure. I am a little frightened of you all, because I am sure there are people here who know every field of knowledge that I have touched much better than I know it.</p>',
            $formatter->format($content, 'application/pdf')
        );
    }

    public function testKeepsParagraphBreaks()
    {
        $formatter = new PdfContentFormatter();

        $this->assertSame(
            '<p>First paragraph.</p><p>Second paragraph continues.</p>',
            $formatter->format('First paragraph.<br /><br />Second paragraph<br />continues.', 'application/pdf')
        );
    }

    public function testRepairsSimpleHyphenatedLineWraps()
    {
        $formatter = new PdfContentFormatter();

        $this->assertSame(
            '<p>recently fault-tolerant systems PDF- Parser</p>',
            $formatter->format('recent-<br />ly<br />fault-tolerant<br />systems<br />PDF-<br />Parser', 'application/pdf')
        );
    }

    public function testReturnsNonPdfContentUnchanged()
    {
        $formatter = new PdfContentFormatter();

        $this->assertSame(
            'First<br />Second',
            $formatter->format('First<br />Second', 'text/html')
        );
    }

    public function testReturnsAlreadyNormalizedPdfContentUnchanged()
    {
        $formatter = new PdfContentFormatter();

        $content = '<p>Already normalized.</p>';
        $formatted = $formatter->format('Already<br />normalized.', 'application/pdf');

        $this->assertSame($content, $formatter->format($content, 'application/pdf'));
        $this->assertSame($formatted, $formatter->format($formatted, 'application/pdf'));
    }

    public function testEscapesAccidentalHtmlFromPdfText()
    {
        $formatter = new PdfContentFormatter();

        $this->assertSame(
            '<p>Look &lt;script&gt;alert(1)&lt;/script&gt; safe &amp; sound</p>',
            $formatter->format('Look <script>alert(1)</script><br />safe & sound', 'application/pdf')
        );
    }
}

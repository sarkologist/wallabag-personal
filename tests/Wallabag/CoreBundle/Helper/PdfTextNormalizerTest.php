<?php

namespace Tests\Wallabag\CoreBundle\Helper;

use PHPUnit\Framework\TestCase;
use Wallabag\CoreBundle\Helper\PdfTextNormalizer;

class PdfTextNormalizerTest extends TestCase
{
    public function testRemovesRecurringPageHeadersAndStandalonePageNumbers()
    {
        $normalizer = new PdfTextNormalizer();
        $text = "Biology of Intentionality Francisco J. Varela\nA different exercise--which I do not pursue here at all--becomes progressively complexi-\n5\fBiology of Intentionality Francisco J. Varela\nfied though reproductive mechanisms, artificial definition specific profiting is not intrinsic.\n6\fBiology of Intentionality Francisco J. Varela\nA final paragraph.";

        $normalized = $normalizer->normalize($text);

        $this->assertStringNotContainsString('Biology of Intentionality Francisco J. Varela', $normalized);
        $this->assertStringNotContainsString("\n5\n", $normalized);
        $this->assertStringNotContainsString("\n6\n", $normalized);
        $this->assertStringContainsString("complexi-\nfied though reproductive mechanisms", $normalized);
        $this->assertStringContainsString('artificial definition specific profiting is not intrinsic', $normalized);
    }

    public function testRemovesRecurringFootersWithEmbeddedControlPageNumbers()
    {
        $normalizer = new PdfTextNormalizer();
        $text = "First page text.\nHeinz von Foerster \x01 On Constructing a Reality\fSecond page text.\nHeinz von Foerster \x02 On Constructing a Reality";

        $normalized = $normalizer->normalize($text);

        $this->assertSame("First page text.\nSecond page text.", $normalized);
    }

    public function testRemovesFuzzySpacedRunningHeadersWithEmbeddedPageNumbers()
    {
        $normalizer = new PdfTextNormalizer();
        $text = "So says Elizabeth Fisher. But no, this cannot be. Where is that wonderful, big, long, hard\fTHE CARR IER BAG THE 0 R Y 0 F F I C T I 0 N 167\nthing, a bone, I believe, that the Ape Man first bashed somebody with.\fT H E C A R R I E R B A G T H E 0 R Y 0 F F I C T I 0 N 169\nThe novel is a fundamentally unheroic kind of story.";

        $normalized = $normalizer->normalize($text);

        $this->assertStringNotContainsString('THE CARR IER BAG', $normalized);
        $this->assertStringNotContainsString('THE 0 R Y', $normalized);
        $this->assertStringNotContainsString("\n167\n", $normalized);
        $this->assertStringNotContainsString("\n169\n", $normalized);
        $this->assertStringContainsString("hard\nthing, a bone", $normalized);
        $this->assertStringContainsString('The novel is a fundamentally unheroic kind of story.', $normalized);
    }

    public function testRemovesFuzzyAuthorRunningHeadersWithDamagedCharacters()
    {
        $normalizer = new PdfTextNormalizer();
        $text = "First page text.\ft66 URSULA K. LE GUIN\nSecond page text.\f170 URSULA K. L\xC2\xA3 GU!N\nThird page text.";

        $normalized = $normalizer->normalize($text);

        $this->assertSame("First page text.\nSecond page text.\nThird page text.", $normalized);
    }
}

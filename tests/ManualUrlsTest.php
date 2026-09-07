<?php

namespace Biigle\Tests\Modules\AskBiigle;

use Biigle\Modules\AskBiigle\ManualUrls;
use TestCase;

class ManualUrlsTest extends TestCase
{
    public function testForSource()
    {
        // The RAG index contains the scraped HTML pages of the manual and the
        // retrieval service reports the file names of their Markdown conversion.
        $this->assertSame(
            'https://biigle.de/manual/tutorials/label-trees/about',
            ManualUrls::forSource('manual_tutorials_label-trees_about.html.md')
        );
        $this->assertSame('https://biigle.de/manual', ManualUrls::forSource('manual.html.md'));
    }

    public function testForSourceUnknown()
    {
        // Sources that are not manual pages have no URL.
        $this->assertNull(ManualUrls::forSource('goldenFacts.md'));
        $this->assertNull(ManualUrls::forSource("An_Ecologist's_Guide_to_BIIGLE.pdf p.40,y:120"));
        $this->assertNull(ManualUrls::forSource(''));
    }

    public function testReplaceInContentLink()
    {
        $this->assertSame(
            '[About](https://biigle.de/manual/tutorials/label-trees/about)',
            ManualUrls::replaceInContent('[About](manual_tutorials_label-trees_about.html.md)')
        );

        // The fragment of a link points to a section of the manual page.
        $this->assertSame(
            '[Shortcuts](https://biigle.de/manual/tutorials/annotations/shortcuts#zoom)',
            ManualUrls::replaceInContent('[Shortcuts](manual_tutorials_annotations_shortcuts.html.md#zoom)')
        );
    }

    public function testReplaceInContentUrl()
    {
        // Some answers use the path of the manual page but the extension of the
        // indexed file.
        $this->assertSame(
            'https://biigle.de/manual/tutorials/notifications',
            ManualUrls::replaceInContent('https://biigle.de/manual/tutorials/notifications.md')
        );

        // Punctuation is not part of the URL.
        $this->assertSame(
            'See https://biigle.de/manual.',
            ManualUrls::replaceInContent('See https://biigle.de/manual.html.md.')
        );
    }

    public function testReplaceInContentFileName()
    {
        $this->assertSame(
            'See https://biigle.de/manual/tutorials/notifications for details.',
            ManualUrls::replaceInContent('See manual_tutorials_notifications.html.md for details.')
        );
    }

    public function testReplaceInContentSourceUrls()
    {
        // Answers sometimes link to a retrieval marker instead of a file name.
        $this->assertSame(
            '[Notifications](https://biigle.de/manual/tutorials/notifications)',
            ManualUrls::replaceInContent('[Notifications](RREF2)', [
                'RREF2' => 'https://biigle.de/manual/tutorials/notifications',
            ])
        );

        // A marker that has no source is left alone.
        $this->assertSame('[Notifications](RREF9)', ManualUrls::replaceInContent('[Notifications](RREF9)'));
    }

    public function testReplaceInContentKeepsOtherContent()
    {
        $cases = [
            // Links to manual pages are already correct.
            '[About](https://biigle.de/manual/tutorials/label-trees/about)',
            // Other pages of BIIGLE and other hosts are none of our business.
            '[Login](https://biigle.de/login)',
            '[Report](https://biigle.de/api/v1/reports/1533)',
            '[Docs](https://example.com/manual.html.md)',
            // A file name is content and not a link if it appears in code.
            'Inline `manual.html.md` code.',
            "```\nmanual.html.md\n```",
            // These files are not part of the manual.
            'README.md and goldenFacts.md',
        ];

        foreach ($cases as $case) {
            $this->assertSame($case, ManualUrls::replaceInContent($case));
        }
    }
}

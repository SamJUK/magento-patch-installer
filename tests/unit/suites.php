<?php

declare(strict_types=1);

namespace SamJUK\MagentoPatchInstaller\Tests;

use SamJUK\MagentoPatchInstaller\Fragment;
use SamJUK\MagentoPatchInstaller\Lifecycle;
use SamJUK\MagentoPatchInstaller\Renderer;
use SamJUK\MagentoPatchInstaller\Splitter;
use SamJUK\MagentoPatchInstaller\TargetResolver;

return [

/* ------------------------------------------------------------------ *
 * Splitter
 * ------------------------------------------------------------------ */

'splitter' => static function (Sandbox $sandbox): void {
    $splitter = new Splitter();

    Assert::case('diff --git, multiple files');
    $fragments = $splitter->split(
        "diff --git a/vendor/magento/framework/Escaper.php b/vendor/magento/framework/Escaper.php\n"
        . "index 1111111..2222222 100644\n"
        . "--- a/vendor/magento/framework/Escaper.php\n"
        . "+++ b/vendor/magento/framework/Escaper.php\n"
        . "@@ -1,2 +1,3 @@\n"
        . " one\n"
        . "+two\n"
        . " three\n"
        . "diff --git a/vendor/magento/module-quote/Model/Quote.php b/vendor/magento/module-quote/Model/Quote.php\n"
        . "--- a/vendor/magento/module-quote/Model/Quote.php\n"
        . "+++ b/vendor/magento/module-quote/Model/Quote.php\n"
        . "@@ -9,1 +9,2 @@\n"
        . " nine\n"
        . "+ten\n"
    );
    Assert::same(2, count($fragments), 'two fragments');
    Assert::same('vendor/magento/framework/Escaper.php', $fragments[0]->target(), 'first target, a/ stripped');
    Assert::same('vendor/magento/module-quote/Model/Quote.php', $fragments[1]->target(), 'second target');
    Assert::false($fragments[0]->createsFile(), 'not a creation');
    Assert::contains($fragments[0]->diff(), 'index 1111111', 'the index line stays in the fragment');
    Assert::false(strpos($fragments[0]->diff(), 'module-quote') !== false, 'fragments do not bleed into each other');
    Assert::same("\n", substr($fragments[1]->diff(), -1), 'diff ends with a newline for git apply');

    Assert::case('plain headers behind a preamble');
    $fragments = $splitter->split(
        "This patch fixes CVE-2024-34102.\n"
        . "Apply with: git apply\n"
        . "\n"
        . "--- a/vendor/magento/framework/Escaper.php\n"
        . "+++ b/vendor/magento/framework/Escaper.php\n"
        . "@@ -1 +1,2 @@\n"
        . " one\n"
        . "+two\n"
        . "--- a/nginx.conf.sample\n"
        . "+++ b/nginx.conf.sample\n"
        . "@@ -5 +5,2 @@\n"
        . " five\n"
        . "+six\n"
    );
    Assert::same(2, count($fragments), 'two fragments from bare header pairs');
    Assert::same('vendor/magento/framework/Escaper.php', $fragments[0]->target(), 'first target');
    Assert::same('nginx.conf.sample', $fragments[1]->target(), 'second target');
    Assert::false(strpos($fragments[0]->diff(), 'This patch fixes') !== false, 'preamble is discarded');
    Assert::same('--- a/vendor/magento/framework/Escaper.php', explode("\n", $fragments[0]->diff())[0], 'fragment starts at its header');

    Assert::case('new file');
    $fragments = $splitter->split(
        "diff --git a/vendor/bin/patch-status b/vendor/bin/patch-status\n"
        . "new file mode 100755\n"
        . "--- /dev/null\n"
        . "+++ b/vendor/bin/patch-status\n"
        . "@@ -0,0 +1,2 @@\n"
        . "+#!/bin/sh\n"
        . "+echo hi\n"
    );
    Assert::same(1, count($fragments), 'one fragment');
    Assert::same('vendor/bin/patch-status', $fragments[0]->target(), 'target is the plus side');
    Assert::true($fragments[0]->createsFile(), 'flagged as creating the file');
    Assert::contains($fragments[0]->diff(), 'new file mode', 'mode line survives');

    Assert::case('new file without a diff --git header');
    $fragments = $splitter->split(
        "--- /dev/null\n"
        . "+++ b/vendor/bin/patch-status\n"
        . "@@ -0,0 +1 @@\n"
        . "+hi\n"
    );
    Assert::same(1, count($fragments), 'one fragment');
    Assert::same('vendor/bin/patch-status', $fragments[0]->target(), 'target from the plus side');
    Assert::true($fragments[0]->createsFile(), 'flagged as creating the file');

    Assert::case('deletion');
    $fragments = $splitter->split(
        "--- a/vendor/magento/module-old/Gone.php\n"
        . "+++ /dev/null\n"
        . "@@ -1 +0,0 @@\n"
        . "-gone\n"
    );
    Assert::same(1, count($fragments), 'one fragment');
    Assert::same('vendor/magento/module-old/Gone.php', $fragments[0]->target(), 'target falls back to the minus side');
    Assert::false($fragments[0]->createsFile(), 'a deletion does not create');

    Assert::case('CRLF line endings');
    $fragments = $splitter->split(
        "diff --git a/a.txt b/a.txt\r\n--- a/a.txt\r\n+++ b/a.txt\r\n@@ -1 +1,2 @@\r\n one\r\n+two\r\n"
    );
    Assert::same(1, count($fragments), 'CRLF still splits');
    Assert::same('a.txt', $fragments[0]->target(), 'no carriage return left on the target');

    Assert::case('trailing newlines are not part of the last hunk');
    $fragments = $splitter->split("--- a/a.txt\n+++ b/a.txt\n@@ -1 +1,2 @@\n one\n+two\n\n\n\n");
    Assert::same(1, count($fragments), 'one fragment');
    Assert::same("--- a/a.txt\n+++ b/a.txt\n@@ -1 +1,2 @@\n one\n+two\n", $fragments[0]->diff(), 'and it ends at the hunk');

    Assert::case('CRLF content survives byte for byte');
    // A patch built against a file stored CRLF carries \r on every context and
    // added line. Normalising it away makes the hunk match nothing.
    $crlf = "diff --git a/a.txt b/a.txt\r\n--- a/a.txt\r\n+++ b/a.txt\r\n"
        . "@@ -1,3 +1,4 @@\r\n one\r\n\r\n+two\r\n three\r\n";
    $fragments = $splitter->split($crlf);
    Assert::same(1, count($fragments), 'one fragment');
    Assert::same($crlf, $fragments[0]->diff(), 'the diff is the input, unchanged');

    Assert::case('an empty CRLF context line does not end the hunk');
    $fragments = $splitter->split(
        "--- a/a.txt\r\n+++ b/a.txt\r\n@@ -1,2 +1,3 @@\r\n\r\n+two\r\n three\r\n"
        . "--- a/b.txt\r\n+++ b/b.txt\r\n@@ -1 +1,2 @@\r\n one\r\n+two\r\n"
    );
    Assert::same(2, count($fragments), 'the blank context line was counted as body');
    Assert::same('b.txt', $fragments[1]->target(), 'and the next fragment still opened');

    Assert::case('timestamped headers');
    $fragments = $splitter->split(
        "--- a/a.txt\t2026-01-01 00:00:00.000000000 +0000\n"
        . "+++ b/a.txt\t2026-01-02 00:00:00.000000000 +0000\n"
        . "@@ -1 +1,2 @@\n one\n+two\n"
    );
    Assert::same('a.txt', $fragments[0]->target(), 'tab-separated timestamp stripped');

    Assert::case('a minus line inside a hunk is not a new header');
    $fragments = $splitter->split(
        "--- a/a.txt\n+++ b/a.txt\n@@ -1,4 +1,4 @@\n"
        . "--- not a header\n"
        . "+++ also not a header\n"
        . " context\n"
    );
    Assert::same(1, count($fragments), 'hunk body does not open a fragment');
    Assert::contains($fragments[0]->diff(), '--- not a header', 'hunk body kept intact');

    Assert::case('a lying hunk header does not swallow the rest of the patch');
    // The counts say four lines follow; two do. Trusting them over what the
    // next line plainly is would fold every later fragment into this one and
    // lose those patches silently.
    $fragments = $splitter->split(
        "diff --git a/a.txt b/a.txt\n--- a/a.txt\n+++ b/a.txt\n@@ -1,4 +1,4 @@\n one\n+two\n"
        . "diff --git a/b.txt b/b.txt\n--- a/b.txt\n+++ b/b.txt\n@@ -1 +1,2 @@\n one\n+two\n"
    );
    Assert::same(2, count($fragments), 'both fragments survive');
    Assert::same('b.txt', $fragments[1]->target(), 'the second is still its own file');

    Assert::case('paths containing spaces');
    $fragments = $splitter->split(
        "diff --git a/some dir/a file.txt b/some dir/a file.txt\n"
        . "--- a/some dir/a file.txt\n"
        . "+++ b/some dir/a file.txt\n"
        . "@@ -1 +1,2 @@\n one\n+two\n"
    );
    Assert::same(1, count($fragments), 'one fragment');
    Assert::same('some dir/a file.txt', $fragments[0]->target(), 'the header pair wins over the diff --git line');

    Assert::case('quoted paths');
    $fragments = $splitter->split(
        "diff --git \"a/some dir/a file.txt\" \"b/some dir/a file.txt\"\n"
        . "--- \"a/some dir/a file.txt\"\n"
        . "+++ \"b/some dir/a file.txt\"\n"
        . "@@ -1 +1,2 @@\n one\n+two\n"
    );
    Assert::same('some dir/a file.txt', $fragments[0]->target(), 'surrounding quotes stripped');
    // The diff text has to agree with the target, or retarget() — which is how
    // the mirrored copy of a root-mapped file is patched — matches nothing and
    // silently reports that copy as in step.
    Assert::contains($fragments[0]->diff(), '--- a/some dir/a file.txt', 'and the header is rewritten to match');
    Assert::contains($fragments[0]->diff(), '+++ b/some dir/a file.txt', 'both sides of the pair');
    // The `diff --git` line keeps its quotes: git reads the paths from the
    // header pair when there is one, and rewriting it would need the escaping
    // logic back again for no gain.
    Assert::contains($fragments[0]->diff(), 'diff --git "a/some dir', 'the diff --git line is left as it was');

    Assert::case('nothing to split');
    Assert::same([], $splitter->split(''), 'empty input');
    Assert::same([], $splitter->split("just some prose\nand more prose\n"), 'prose with no headers');

    Assert::case('a fragment with no hunk at all');
    // A mode change or a pure rename carries no `---`/`+++` pair, so the
    // `diff --git` line is the only thing naming the file. Keep it: git can
    // apply it, and dropping it would be a silent skip.
    $fragments = $splitter->split("diff --git a/a.txt b/a.txt\nold mode 100644\nnew mode 100755\n");
    Assert::same(1, count($fragments), 'a mode-only fragment survives');
    Assert::same('a.txt', $fragments[0]->target(), 'named from the diff --git line');
    Assert::contains($fragments[0]->diff(), 'new mode 100755', 'with the mode lines intact');

    Assert::case('a null byte in a diff header is refused');
    // git's quoted header form carries \0 through stripcslashes as a real NUL,
    // and realpath()/hash_file() throw ValueError on one rather than returning
    // false. Nothing between the plugin's event handler and there catches
    // Throwable, so this was a stack trace out of every consumer's
    // composer install. No such file can exist.
    $poisoned = (new Splitter())->split(
        "--- \"a/vendor/evil/x\\0y\"\n+++ \"b/vendor/evil/x\\0y\"\n@@ -1 +1 @@\n-a\n+b\n"
    );
    Assert::same([], $poisoned, 'the fragment is dropped, so the patch reads as unreadable');

    $clean = (new Splitter())->split("--- a/x.txt\n+++ b/x.txt\n@@ -1 +1 @@\n-a\n+b\n");
    Assert::same(1, count($clean), 'an ordinary quoted-free header still splits');
},

/* ------------------------------------------------------------------ *
 * TargetResolver
 * ------------------------------------------------------------------ */

'resolver' => static function (Sandbox $sandbox): void {
    $root = $sandbox->mkdir('project');
    $install = 'vendor/magento/magento2-base';

    $sandbox->write('project/' . $install . '/nginx.conf.sample', "server\n");
    $sandbox->write('project/' . $install . '/app/etc/di.xml', "<config/>\n");
    $sandbox->write('project/' . $install . '/app/etc/db_schema.xml', "<schema/>\n");
    $sandbox->write('project/nginx.conf.sample', "server\n");
    $sandbox->write('project/app/etc/di.xml', "<config/>\n");

    // The shape magento2-base actually publishes: a mix of single files and
    // whole directories, plus a file entry nested inside one of those
    // directories, which only resolves correctly if longer destinations win.
    $resolver = new TargetResolver($root, [[
        'package' => 'magento/magento2-base',
        'install' => $install,
        'map' => [
            ['nginx.conf.sample', 'nginx.conf.sample'],
            ['app/etc', 'app/etc'],
            ['app/etc/db_schema.xml', 'app/etc/db_schema.xml'],
        ],
    ]]);

    Assert::case('a package cannot claim a destination inside another package');
    // One line of JSON in a dependency nobody trusts otherwise takes over the
    // mirror lookup for a file it does not own: the longest destination wins
    // the sort, so the served copy quietly stops being patched while the
    // report still says applied. The mirror is the entire defence against a
    // magento2-base deploy undoing a root patch.
    $sandbox->write('project/vendor/noise/analytics/decoy', "server\n");
    $hijacked = new TargetResolver($root, [
        [
            'package' => 'magento/magento2-base',
            'install' => $install,
            'map' => [['nginx.conf.sample', 'nginx.conf.sample']],
        ],
        [
            'package' => 'noise/analytics',
            'install' => 'vendor/noise/analytics',
            'map' => [['decoy', $install . '/nginx.conf.sample']],
        ],
    ]);
    Assert::same(
        'nginx.conf.sample',
        $hijacked->mirrorFor($install . '/nginx.conf.sample'),
        'the real deploy mapping still wins'
    );
    Assert::false(
        $hijacked->mirrorFor('nginx.conf.sample') === 'vendor/noise/analytics/decoy',
        'and the hijacked destination is never the mirror'
    );

    Assert::case('escapesProject');
    // writePathFor() hands back the unresolved path when it escapes, which
    // absolute() then turns straight back into the escaping path — so the
    // mirror copy, the one write that does not go through git apply, has to
    // ask this question instead.
    $outside = $sandbox->mkdir('outside');
    $sandbox->write('outside/victim.php', "<?php // theirs\n");
    @symlink($outside, $root . '/shared');

    if (is_link($root . '/shared')) {
        Assert::same(true, $resolver->escapesProject('shared/victim.php'), 'through a symlinked directory');
        Assert::same(true, $resolver->escapesProject('shared/new.php'), 'including a file not there yet');
        Assert::same(false, $resolver->escapesProject('nginx.conf.sample'), 'an ordinary path does not');
        Assert::same(false, $resolver->escapesProject('app/etc/nothing-here.xml'), 'nor an ordinary missing one');
    }

    Assert::case('mirrorFor');
    Assert::same(
        $install . '/nginx.conf.sample',
        $resolver->mirrorFor('nginx.conf.sample'),
        'root file resolves to its package original'
    );
    Assert::same(
        'nginx.conf.sample',
        $resolver->mirrorFor($install . '/nginx.conf.sample'),
        'and back the other way'
    );
    Assert::same(
        $install . '/app/etc/di.xml',
        $resolver->mirrorFor('app/etc/di.xml'),
        'a file under a mapped directory keeps its suffix'
    );
    Assert::same(
        'app/etc/di.xml',
        $resolver->mirrorFor($install . '/app/etc/di.xml'),
        'and back the other way'
    );
    Assert::same(
        $install . '/app/etc/db_schema.xml',
        $resolver->mirrorFor('app/etc/db_schema.xml'),
        'a file entry nested in a mapped directory still resolves'
    );
    Assert::same(
        null,
        $resolver->mirrorFor('vendor/magento/module-quote/Model/Quote.php'),
        'an ordinary module path has no mirror'
    );
    Assert::same(null, $resolver->mirrorFor('app/code/Vendor/Module/di.xml'), 'app/code is not mapped');
    Assert::same(
        $install . '/nginx.conf.sample',
        $resolver->mirrorFor('/nginx.conf.sample'),
        'a leading slash is tolerated'
    );

    Assert::case('mirrorFor rejects prefix collisions');
    Assert::same(null, $resolver->mirrorFor('nginx.conf.sample.bak'), 'a longer name is not the mapped file');
    Assert::same(null, $resolver->mirrorFor('app/etcetera/di.xml'), 'a longer directory name is not the mapped one');

    Assert::case('malformed map entries');
    $tolerant = new TargetResolver($root, [[
        'package' => 'magento/magento2-base',
        'install' => $install,
        'map' => [
            'not-a-pair',
            ['only-one'],
            ['', 'empty-src'],
            ['empty-dest', ''],
            ['nginx.conf.sample', 'nginx.conf.sample'],
        ],
    ]]);
    Assert::same(
        $install . '/nginx.conf.sample',
        $tolerant->mirrorFor('nginx.conf.sample'),
        'junk entries are skipped, the good one still works'
    );

    Assert::case('absolute and hash');
    Assert::same($root . '/nginx.conf.sample', $resolver->absolute('nginx.conf.sample'), 'absolute path');
    Assert::same($root . '/nginx.conf.sample', $resolver->absolute('/nginx.conf.sample'), 'leading slash tolerated');
    Assert::same(
        hash('sha256', "server\n"),
        $resolver->hash('nginx.conf.sample'),
        'hash of the file contents'
    );
    Assert::same(null, $resolver->hash('does/not/exist'), 'no hash for a missing file');
    Assert::same(null, $resolver->hash('app/etc'), 'no hash for a directory');

    Assert::case('writePathFor');
    Assert::same(
        'nginx.conf.sample',
        $resolver->writePathFor('nginx.conf.sample'),
        'an ordinary file is written where it is'
    );

    // The `symlink` deploy strategy: git apply refuses to write through the
    // link, so the resolver has to hand back the real file instead.
    symlink($root . '/' . $install . '/app/etc/di.xml', $root . '/linked.xml');
    Assert::same(
        $install . '/app/etc/di.xml',
        $resolver->writePathFor('linked.xml'),
        'a symlink resolves to its target, relative to the project'
    );

    $outside = $sandbox->write('outside.txt', "elsewhere\n");
    symlink($outside, $root . '/escapes.txt');
    Assert::same(
        'escapes.txt',
        $resolver->writePathFor('escapes.txt'),
        'a link out of the project is left alone rather than followed'
    );

    symlink($root . '/nowhere-at-all', $root . '/broken.txt');
    Assert::same('broken.txt', $resolver->writePathFor('broken.txt'), 'a broken link is left alone');

    Assert::case('writePathFor resolves symlinked parents, not just leaves');
    // git apply refuses anything "beyond a symbolic link", and it means any
    // link in the path. Composer path repositories symlink vendor/<v>/<p> by
    // default, and shared-storage deploys symlink vendor/ itself.
    $sandbox->write('real-store/pkg/File.php', "<?php\n");
    symlink($sandbox->root() . '/real-store/pkg', $root . '/vendor/linked-pkg');
    Assert::same(
        'vendor/linked-pkg/File.php',
        $resolver->writePathFor('vendor/linked-pkg/File.php'),
        'a parent link out of the project is left alone'
    );

    $sandbox->write('project/store/pkg/File.php', "<?php\n");
    symlink($root . '/store/pkg', $root . '/vendor/inside-pkg');
    Assert::same(
        'store/pkg/File.php',
        $resolver->writePathFor('vendor/inside-pkg/File.php'),
        'a parent link inside the project resolves to the real file'
    );
    Assert::same(
        'store/pkg/New.php',
        $resolver->writePathFor('vendor/inside-pkg/New.php'),
        'and so does a file the patch has yet to create'
    );

    Assert::case('map entries cannot climb out of the project');
    $hostile = new TargetResolver($root, [[
        'package' => 'evil/package',
        'install' => 'vendor/evil/package',
        'map' => [
            ['../../../../etc/passwd', 'passwd'],
            ['x', '../../outside.txt'],
            ['nginx.conf.sample', 'nginx.conf.sample'],
        ],
    ]]);
    Assert::same(null, $hostile->mirrorFor('passwd'), 'a traversing src is dropped');
    Assert::same(null, $hostile->mirrorFor('../../outside.txt'), 'a traversing dest is dropped');
    Assert::same(
        'vendor/evil/package/nginx.conf.sample',
        $hostile->mirrorFor('nginx.conf.sample'),
        'the well-formed entry still works'
    );

    Assert::case('isSameFile');
    // The `link` deploy strategy leaves one inode with two names, so patching
    // "both copies" would apply the same diff to the same file twice.
    link($root . '/' . $install . '/app/etc/db_schema.xml', $root . '/hard.xml');
    Assert::true(
        $resolver->isSameFile('hard.xml', $install . '/app/etc/db_schema.xml'),
        'a hardlink is the same file'
    );
    Assert::false(
        $resolver->isSameFile('nginx.conf.sample', $install . '/nginx.conf.sample'),
        'identical copies are still two files'
    );
    Assert::false($resolver->isSameFile('nginx.conf.sample', 'missing.txt'), 'a missing file is not the same file');
    Assert::true($resolver->isSameFile('nginx.conf.sample', 'nginx.conf.sample'), 'a file is itself');

    Assert::case('a project root with a trailing slash');
    $trailing = new TargetResolver($root . '/', [[
        'package' => 'magento/magento2-base',
        'install' => $install,
        'map' => [['nginx.conf.sample', 'nginx.conf.sample']],
    ]]);
    Assert::same($root . '/nginx.conf.sample', $trailing->absolute('nginx.conf.sample'), 'no doubled slash');
    Assert::same(
        $install . '/nginx.conf.sample',
        $trailing->mirrorFor('nginx.conf.sample'),
        'mapping still resolves'
    );
},

/* ------------------------------------------------------------------ *
 * Lifecycle
 * ------------------------------------------------------------------ */

'lifecycle' => static function (Sandbox $sandbox): void {
    $manifest = $sandbox->write('eol.json', (string) json_encode([
        'source' => 'test',
        'generated' => '2026-01-01',
        'distributions' => [
            'magento/product-community-edition' => [
                '2.4.6-p15' => '2026-08-11',
                '2.4.8-p5' => '2027-04-09',
                '2.4.9' => '2028-01-01',
                '2.4.0-broken' => 'not-a-date',
            ],
            'magento/product-enterprise-edition' => [
                '2.4.6-p15' => '2026-08-11',
            ],
            'mage-os/product-community-edition' => [
                '1.2.0' => '2029-01-01',
            ],
        ],
    ]));

    $lifecycle = new Lifecycle($manifest);
    $on = static function (string $date): \DateTimeImmutable {
        return new \DateTimeImmutable($date . ' 00:00:00');
    };

    Assert::case('past end of life');
    $result = $lifecycle->check(['magento/product-community-edition' => '2.4.6-p15'], $on('2026-09-09'));
    Assert::same(Lifecycle::ENDED, $result['status'], 'status');
    Assert::same(-29, $result['days'], 'days is negative and counts back to the date');
    $advisory = $lifecycle->advisory(['magento/product-community-edition' => '2.4.6-p15'], $on('2026-09-09'));
    Assert::same('critical', $advisory['level'], 'critical level');
    Assert::contains($advisory['message'], 'reached end of life on 2026-08-11', 'names the date');
    Assert::contains($advisory['message'], '(29 days ago)', 'days ago is positive in the prose');

    Assert::case('the day itself is not yet over');
    $result = $lifecycle->check(['magento/product-community-edition' => '2.4.6-p15'], $on('2026-08-11'));
    Assert::same(Lifecycle::APPROACHING, $result['status'], 'on the date, still approaching');
    Assert::same(0, $result['days'], 'zero days left');

    Assert::case('the notice window');
    // 182 days is the boundary: inside it warns, one day outside it says nothing.
    $result = $lifecycle->check(['magento/product-community-edition' => '2.4.8-p5'], $on('2026-10-09'));
    Assert::same(Lifecycle::APPROACHING, $result['status'], '182 days out is inside the window');
    Assert::same(182, $result['days'], 'exactly the boundary');
    Assert::same(
        'warning',
        $lifecycle->advisory(['magento/product-community-edition' => '2.4.8-p5'], $on('2026-10-09'))['level'],
        'and it is a warning, not critical'
    );

    $result = $lifecycle->check(['magento/product-community-edition' => '2.4.8-p5'], $on('2026-10-08'));
    Assert::same(Lifecycle::OK, $result['status'], '183 days out is outside the window');
    Assert::same(
        null,
        $lifecycle->advisory(['magento/product-community-edition' => '2.4.8-p5'], $on('2026-10-08')),
        'and says nothing at all'
    );

    Assert::case('quiet where it cannot know');
    Assert::same(
        null,
        $lifecycle->check(['magento/product-community-edition' => '2.4.99'], $on('2026-09-09')),
        'an unreleased version is not assumed to be fine'
    );
    Assert::same(null, $lifecycle->check([], $on('2026-09-09')), 'no product package installed');
    Assert::same(
        null,
        $lifecycle->check(['magento/framework' => '103.0.7'], $on('2026-09-09')),
        'a non-product package is not a product'
    );
    Assert::same(
        null,
        $lifecycle->check(['magento/product-community-edition' => '2.4.0-broken'], $on('2026-09-09')),
        'an unparseable date is ignored rather than guessed at'
    );

    Assert::case('Commerce is reported before Open Source');
    // Adobe Commerce installs both product packages, so order decides which
    // name the store sees itself called.
    $result = $lifecycle->check([
        'magento/product-community-edition' => '2.4.6-p15',
        'magento/product-enterprise-edition' => '2.4.6-p15',
    ], $on('2026-09-09'));
    Assert::same('magento/product-enterprise-edition', $result['package'], 'enterprise wins');

    Assert::case('mage-os');
    $result = $lifecycle->check(['mage-os/product-community-edition' => '1.2.0'], $on('2026-09-09'));
    Assert::same(Lifecycle::OK, $result['status'], 'a supported mage-os release');
    Assert::same('mage-os/product-community-edition', $result['package'], 'named correctly');

    Assert::case('a missing or broken manifest is survivable');
    $missing = new Lifecycle($sandbox->root() . '/no-such-file.json');
    Assert::same(null, $missing->check(['magento/product-community-edition' => '2.4.6-p15']), 'no manifest, no claim');
    Assert::same(null, $missing->generated(), 'and no generated date');

    $garbage = new Lifecycle($sandbox->write('garbage.json', 'not json at all'));
    Assert::same(null, $garbage->check(['magento/product-community-edition' => '2.4.6-p15']), 'garbage, no claim');

    Assert::case('the manifest this package actually ships');
    $shipped = new Lifecycle();
    Assert::true($shipped->generated() !== null, 'has a generated date');
    $result = $shipped->check(['magento/product-community-edition' => '2.4.6-p15'], $on('2026-09-09'));
    Assert::true($result !== null, '2.4.6-p15 is a version the shipped manifest knows');
    Assert::same(Lifecycle::ENDED, $result['status'], 'and it is past end of life');

    // Every date in the shipped file has to parse, or the warning silently
    // disappears for whichever versions are malformed.
    $raw = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/magento-eol.json'), true);
    $bad = [];
    foreach ($raw['distributions'] as $package => $versions) {
        foreach ($versions as $version => $date) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $date);

            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                $bad[] = $package . ' ' . $version . ' => ' . var_export($date, true);
            }
        }
    }
    Assert::same([], $bad, 'every shipped date parses as Y-m-d');
},

/* ------------------------------------------------------------------ *
 * Fragment
 * ------------------------------------------------------------------ */

'fragment' => static function (Sandbox $sandbox): void {
    Assert::case('owningPackage');
    Assert::same(
        'magento/framework',
        fragment('vendor/magento/framework/Escaper.php', Fragment::APPLICABLE)->owningPackage(),
        'a vendor path names its package'
    );
    Assert::same(
        null,
        fragment('nginx.conf.sample', Fragment::APPLICABLE)->owningPackage(),
        'a project root file belongs to no package'
    );
    Assert::same(
        null,
        fragment('vendor/magento/framework', Fragment::APPLICABLE)->owningPackage(),
        'the package directory itself is not inside a package'
    );
    Assert::same(
        null,
        fragment('app/code/Vendor/Module/di.xml', Fragment::APPLICABLE)->owningPackage(),
        'app/code is not vendor'
    );

    Assert::case('verdict predicates');
    $expected = [
        // verdict                  => [wasWritten, isFailure, isUnapplied]
        Fragment::APPLICABLE        => [false, false, true],
        Fragment::APPLIED           => [true,  false, false],
        Fragment::SUPERSEDED        => [true,  false, false],
        Fragment::ALREADY           => [false, false, false],
        Fragment::NOT_APPLICABLE    => [false, false, false],
        Fragment::CONFLICT          => [false, true,  false],
        Fragment::MIRROR_CONFLICT   => [false, true,  false],
        Fragment::REVERTED          => [false, false, false],
    ];

    foreach ($expected as $verdict => [$written, $failure, $unapplied]) {
        $f = fragment('a.txt', $verdict);
        Assert::same($written, $f->wasWritten(), $verdict . ' wasWritten');
        Assert::same($failure, $f->isFailure(), $verdict . ' isFailure');
        Assert::same($unapplied, $f->isUnapplied(), $verdict . ' isUnapplied');
    }

    Assert::case('written paths');
    $f = fragment('a.txt', Fragment::APPLIED);
    Assert::same([], fragment('a.txt', Fragment::APPLIED)->written(), 'nothing written by default');
    $f->recordWrite('a.txt');
    $f->recordWrite('vendor/pkg/a.txt');
    Assert::same(['a.txt', 'vendor/pkg/a.txt'], $f->written(), 'primary first, mirror second');
},

/* ------------------------------------------------------------------ *
 * Renderer
 * ------------------------------------------------------------------ */

'renderer' => static function (Sandbox $sandbox): void {
    Assert::case('exit codes');
    Assert::same(Renderer::OK, Renderer::exitCode([]), 'nothing declared');
    Assert::same(
        Renderer::OK,
        Renderer::exitCode([result(true, [fragment('a.txt', Fragment::ALREADY)])]),
        'everything already applied'
    );
    Assert::same(
        Renderer::OK,
        Renderer::exitCode([result(true, [
            fragment('a.txt', Fragment::APPLIED),
            fragment('b.txt', Fragment::NOT_APPLICABLE),
        ])]),
        'applied now, plus a target that does not exist here'
    );
    Assert::same(
        Renderer::UNAPPLIED,
        Renderer::exitCode([result(true, [fragment('a.txt', Fragment::APPLICABLE)])]),
        'applicable but not applied is the silent-revert case'
    );
    Assert::same(
        Renderer::CONFLICT,
        Renderer::exitCode([result(true, [fragment('a.txt', Fragment::CONFLICT)])]),
        'a conflict'
    );
    Assert::same(
        Renderer::CONFLICT,
        Renderer::exitCode([result(true, [fragment('a.txt', Fragment::MIRROR_CONFLICT)])]),
        'a mirror conflict counts the same'
    );

    Assert::case('conflict outranks unapplied whichever comes first');
    Assert::same(
        Renderer::CONFLICT,
        Renderer::exitCode([
            result(true, [fragment('a.txt', Fragment::APPLICABLE)]),
            result(true, [fragment('b.txt', Fragment::CONFLICT)]),
        ]),
        'unapplied first'
    );
    Assert::same(
        Renderer::CONFLICT,
        Renderer::exitCode([
            result(true, [fragment('b.txt', Fragment::CONFLICT)]),
            result(true, [fragment('a.txt', Fragment::APPLICABLE)]),
        ]),
        'conflict first'
    );

    Assert::case('patches for another base version do not fail the run');
    // The regression this guards: a patch built for 2.4.8-p5 on a 2.4.6 store
    // is not applicable, and its fragments were never classified. Counting them
    // made every store exit non-zero.
    Assert::same(
        Renderer::OK,
        Renderer::exitCode([result(false, [fragment('a.txt', Fragment::APPLICABLE)])]),
        'an inapplicable patch is ignored entirely'
    );

    Assert::case('rendering');
    $lines = implode("\n", Renderer::lines([result(true, [
        fragment('vendor/magento/framework/Escaper.php', Fragment::ALREADY),
        fragment('nginx.conf.sample', Fragment::CONFLICT),
    ], '2026-09-001', 'Session Reaper')], false));
    Assert::contains($lines, '2026-09-001', 'the id is printed');
    Assert::contains($lines, 'Session Reaper', 'and the name beside it');
    Assert::contains($lines, '2.4.8-p5', 'and the base version it was built for');
    Assert::contains($lines, '1 of 2', 'the tally shows the shortfall');
    Assert::contains($lines, 'nginx.conf.sample', 'a failing target is always named');
    Assert::false(
        strpos($lines, 'Escaper') !== false,
        'a target that is simply fine is not listed without -v'
    );

    Assert::case('paths lose the vendor prefix they all share');
    $lines = implode("\n", Renderer::lines([result(true, [
        fragment('vendor/magento/framework/Escaper.php', Fragment::APPLICABLE),
    ])], false));
    Assert::contains($lines, 'magento/framework/Escaper.php', 'the useful part of the path');
    Assert::false(strpos($lines, 'vendor/magento') !== false, 'and not the seven columns of vendor/');

    Assert::case('bad states are upper case, so they read without colour');
    Assert::contains($lines, 'MISSING', 'an unapplied target shouts');
    $fine = implode("\n", Renderer::lines([result(true, [
        fragment('a.txt', Fragment::ALREADY),
    ])], true));
    Assert::contains($fine, 'in place', 'a fine one does not');

    Assert::case('patches for other versions collapse instead of crowding');
    // The complaint this fixes: four release lines means sixteen rows of "not
    // for you", each dragging a version constraint behind it.
    $mixed = [
        result(true, [fragment('a.txt', Fragment::ALREADY)]),
        result(false, [], '2026-07-001', '', '2.4.6-p15'),
        result(false, [], '2026-08-001', '', '2.4.6-p15'),
        result(false, [], '2026-07-001', '', '2.4.7-p10'),
    ];

    $quiet = implode("\n", Renderer::lines($mixed, false));
    Assert::contains($quiet, '3 patches built for another base version', 'counted, not listed');
    Assert::contains($quiet, '-v to list', 'and it says how to see them');
    Assert::false(strpos($quiet, '2.4.6-p15') !== false, 'no per-patch rows for them');

    $loud = implode("\n", Renderer::lines($mixed, true));
    Assert::contains($loud, '2.4.6-p15  2026-07-001, 2026-08-001', 'grouped by base version under -v');
    Assert::contains($loud, '2.4.7-p10  2026-07-001', 'each line its own row');

    Assert::case('not-covered targets are footnoted even without -v');
    // Asked for explicitly: otherwise the only trace of a target nobody is
    // patching is a number in the headline.
    $covered = implode("\n", Renderer::lines([result(true, [
        fragment('a.txt', Fragment::ALREADY),
        fragment('vendor/magento/module-customer-graph-ql/x.php', Fragment::NOT_APPLICABLE),
    ])], false));
    Assert::contains($covered, '1 target not covered on this store', 'said out loud');
    Assert::contains($covered, 'replaced or not installed', 'and why that happens');

    Assert::case('a blocked chain member is never silent');
    $blocked = result(false, [], '2026-08-001');
    $blocked['blocked'] = true;
    $blocked['reason'] = 'requires 2026-07-001, which is not applied';
    $lines = implode("\n", Renderer::lines([$blocked], false));
    Assert::contains($lines, 'BLOCKED', 'said out loud even without -v');
    Assert::contains($lines, 'requires 2026-07-001', 'and says which link is missing');

    Assert::case('a refused reversal is a failure, and says so');
    // The one deliberate call in the skip design: a reversal that will not
    // apply leaves the store carrying a patch the project believes is switched
    // off, which is the same class of lie as a missing patch looking present.
    $refused = result(true, [fragment('a.txt', Fragment::CONFLICT)], '2026-08-001');
    $refused['applicable'] = false;
    $refused['skipped'] = 'breaks our checkout';

    Assert::same(Renderer::CONFLICT, Renderer::exitCode([$refused]), 'it moves the exit code');

    $clean = result(true, [fragment('a.txt', Fragment::REVERTED)], '2026-08-001');
    $clean['applicable'] = false;
    $clean['skipped'] = 'breaks our checkout';
    Assert::same(Renderer::OK, Renderer::exitCode([$clean]), 'a reversal that worked does not');

    $present = result(true, [fragment('a.txt', Fragment::UNWANTED)], '2026-08-001');
    $present['applicable'] = false;
    $present['skipped'] = 'breaks our checkout';
    Assert::same(Renderer::OK, Renderer::exitCode([$present]), 'and nor does one still to happen');

    Assert::case('a skipped patch shows what it did to the working tree');
    $lines = implode("\n", Renderer::lines([$refused], false));
    Assert::contains($lines, 'SKIPPED', 'the row says it was switched off');
    Assert::contains($lines, 'breaks our checkout', 'with the reason');
    Assert::contains($lines, 'CONFLICT', 'and names the target it could not clear');

    Assert::case('package-controlled text cannot forge rows');
    // A description carrying a newline could otherwise paint a convincing
    // extra line into the report.
    $forged = [[
        'id' => 'EVIL', 'label' => 'EVIL', 'owner' => 'acme/x', 'source' => 'a.patch',
        'line' => null, 'targets' => 1, 'applicable' => true,
        'reason' => null, 'depends' => [],
        'description' => "harmless\n  ok   2026-09-001  2.4.8-p5  1 of 1",
    ]];
    $text = implode("\n", Renderer::catalogue($forged, true));
    Assert::false(
        strpos($text, "\n  ok   2026-09-001") !== false,
        'the newline is gone, so the forged row cannot start a line'
    );

    Assert::case('the banner is the last word');
    $ok = Renderer::banner([result(true, [fragment('a.txt', Fragment::ALREADY)])], []);
    Assert::contains($ok, 'OK', 'a clean store');
    Assert::contains($ok, '1 of 1 targets applied', 'with the numbers behind it');

    $warn = Renderer::banner(
        [result(true, [fragment('a.txt', Fragment::ALREADY)])],
        [],
        [['level' => 'critical', 'message' => 'past end of life']]
    );
    Assert::contains($warn, 'WARN', 'everything applied, but something needs attention');
    Assert::contains($warn, '1 advisory above', 'pointing at it');

    $issue = Renderer::banner([result(true, [fragment('a.txt', Fragment::APPLICABLE)])], []);
    Assert::contains($issue, '!!! ISSUE', 'punctuation, because CI logs have no colour');
    Assert::contains($issue, '1 target not applied', 'and says what is wrong');
    Assert::contains($issue, 'composer patches:apply', 'and what to do about it');

    $conflict = Renderer::banner([result(true, [fragment('a.txt', Fragment::CONFLICT)])], []);
    Assert::contains($conflict, '!!! ISSUE', 'a conflict is an issue too');
    Assert::contains($conflict, 'nothing was overwritten', 'and says the file is untouched');
    Assert::contains($conflict, '1 target match', 'counted, and in the singular');

    $conflicts = Renderer::banner([result(true, [
        fragment('a.txt', Fragment::CONFLICT),
        fragment('b.txt', Fragment::CONFLICT),
    ])], []);
    Assert::contains($conflicts, '2 targets match', 'and in the plural for more than one');

    // A refused reversal is the documented headline case for the skip feature,
    // and it is not applicable — which is what totals() used to skip, so the
    // run correctly exited 2 under the sentence "0 target match neither side".
    $refused = result(false, [fragment('a.txt', Fragment::CONFLICT)]);
    $refused['skipped'] = 'breaks our checkout';
    $banner = Renderer::banner([$refused], []);
    Assert::contains($banner, '!!! ISSUE', 'a reversal that will not apply fails the run');
    Assert::contains($banner, '1 target match', 'and the sentence explaining it counts it');
    Assert::same(Renderer::CONFLICT, Renderer::exitCode([$refused]), 'exit code agrees');

    Assert::case('--json survives bytes that are not valid UTF-8');
    // A quoted diff header is enough to put invalid bytes into a target path,
    // and git's stderr into a reason. json_encode then returns false, and
    // (string) false is '' — so `patches:verify --json` printed an empty
    // document while a security patch was genuinely missing, and anything
    // parsing the body saw nothing wrong.
    $nasty = result(true, [fragment("vendor/x/\xC3\x28y.php", Fragment::APPLICABLE)]);
    $json = Renderer::json([$nasty], []);
    Assert::false($json === '', 'the body is not empty');
    Assert::same(
        true,
        is_array(json_decode($json, true)),
        'and it parses'
    );

    Assert::case('footnotes cannot forge a row either');
    // The one surface that did not route package text through safe(): the
    // "built for another base version" list, which is most of the rows on a
    // real store.
    $evil = result(false, [fragment('a.txt', Fragment::NOT_APPLICABLE)], "EVIL\n<info>  OK  </info>");
    $evil['reason'] = 'built for another base version';
    $text = implode("\n", Renderer::lines([$evil], true));
    Assert::false(
        strpos($text, "\n<info>  OK  </info>") !== false,
        'the newline is gone, so nothing can start a forged line'
    );

    $broken = Renderer::banner([], ['fake/pkg is not trusted']);
    Assert::contains($broken, '!!! ISSUE', 'a configuration error is an issue');
    Assert::contains($broken, 'patches were dropped', 'and never reads as nothing to do');

    Assert::case('a store past end of life still says OK about its patches');
    // The banner reports the patching, not the store. An unsupported store
    // needs its patches more than a supported one does.
    $advisory = Renderer::banner(
        [result(true, [fragment('a.txt', Fragment::ALREADY)])],
        [],
        [['level' => 'critical', 'message' => 'reached end of life']]
    );
    Assert::false(strpos($advisory, 'ISSUE') !== false, 'not an issue');

    Assert::case('summary does not reassure when nothing could be read');
    // "0 patches: nothing to do" on a store whose trust entry is missing reads
    // as an all-clear, and it is the opposite.
    Assert::contains(
        Renderer::summary([], ['fake/pkg declares patches but is not trusted']),
        'no patches could be read',
        'errors with no results say so'
    );
    Assert::same(
        Renderer::headline([]),
        Renderer::summary([], []),
        'no errors, no results: the ordinary headline'
    );
    $some = [result(true, [fragment('a.txt', Fragment::ALREADY)])];
    Assert::same(
        Renderer::headline($some),
        Renderer::summary($some, ['something else went wrong']),
        'results present: the headline still counts them'
    );

    Assert::case('the catalogue is about the patches, not the store');
    $entries = [
        ['id' => 'APSB25-94', 'label' => 'APSB25-94 Polyshell', 'owner' => 'samjuk/meta',
         'source' => 'patches/emergency/polyshell.patch', 'line' => null,
         'targets' => 2, 'applicable' => true, 'reason' => null, 'depends' => [], 'description' => null],
        ['id' => '2026-07-001', 'label' => '2026-07-001 [2.4.6-p15]', 'owner' => 'samjuk/meta',
         'source' => 'patches/isolated/246p15-2026-07-001.patch', 'line' => '2.4.6-p15',
         'targets' => 30, 'applicable' => false, 'reason' => 'built for magento/magento2-base 2.4.6-p15',
         'depends' => [], 'description' => 'July isolated release'],
        ['id' => '2026-08-001', 'label' => '2026-08-001 [2.4.6-p15]', 'owner' => 'samjuk/meta',
         'source' => 'patches/isolated/246p15-2026-08-001.patch', 'line' => '2.4.6-p15',
         'targets' => 9, 'applicable' => false, 'reason' => 'built for magento/magento2-base 2.4.6-p15',
         'depends' => ['2026-07-001'], 'description' => null],
    ];

    $quiet = Renderer::catalogue($entries, false);
    $text = implode("\n", $quiet);
    Assert::contains($text, 'samjuk/meta', 'grouped under the package that declares it');
    Assert::contains($text, 'after 2026-07-001', 'what a patch is built on is part of the catalogue');
    Assert::contains($text, 'applies here', 'and which of them are for this install');
    Assert::contains($text, '3 patches · 1 apply to this install', 'counted at the end');

    // The base version is printed once per group. Sixteen rows that each repeat
    // it are sixteen rows where the column that varies is hardest to find.
    $repeats = 0;
    foreach ($quiet as $line) {
        if (strpos($line, '2.4.6-p15') !== false) {
            $repeats++;
        }
    }
    Assert::same(1, $repeats, 'the base version heads its group rather than repeating');

    Assert::false(
        strpos($text, 'patches/isolated/246p15-2026-07-001.patch') !== false,
        'source paths are detail, not headline'
    );
    Assert::contains(
        implode("\n", Renderer::catalogue($entries, true)),
        'patches/isolated/246p15-2026-07-001.patch',
        'and -v shows them'
    );
    Assert::contains(
        implode("\n", Renderer::catalogue($entries, true)),
        'July isolated release',
        'along with the description, for a friendly name beside the date'
    );

    Assert::same(['  No patches declared.'], Renderer::catalogue([], false), 'nothing declared');

    Assert::case('the status header describes the store');
    $env = Renderer::environment([
        'product' => ['package' => 'magento/product-community-edition', 'version' => '2.4.6-p15'],
        'lifecycle' => ['status' => 'ended', 'package' => 'magento/product-community-edition',
                        'version' => '2.4.6-p15', 'date' => '2026-08-11', 'days' => -29],
        'sources' => ['samjuk/m2-meta-security-patches' => 19],
        'lastApplied' => '2026-09-09T18:04:00+00:00',
        'manifest' => '2026-09-01',
    ]);
    $text = implode("\n", $env);
    Assert::contains($text, 'magento/product-community-edition 2.4.6-p15', 'names the store');
    Assert::contains($text, 'passed 29 days ago', 'and how far past end of life it is');
    Assert::contains($text, 'samjuk/m2-meta-security-patches (19)', 'where its patches come from');
    Assert::contains($text, '2026-09-09', 'and when they were last written');

    Assert::case('an unknown version is said to be unknown, not assumed fine');
    Assert::contains(
        implode("\n", Renderer::environment([
            'product' => ['package' => 'magento/product-community-edition', 'version' => '2.4.99'],
            'lifecycle' => null,
            'sources' => [],
            'lastApplied' => null,
            'manifest' => null,
        ])),
        'newer than the shipped manifest',
        'the manifest predates this release'
    );

    Assert::case('verify prints what is wrong and nothing else');
    $mixed = [
        result(true, [fragment('a.txt', Fragment::ALREADY)], '2026-07-001'),
        result(true, [fragment('b.txt', Fragment::APPLICABLE)], '2026-09-001'),
    ];
    $all = implode("\n", Renderer::lines($mixed, false, false));
    $bad = implode("\n", Renderer::lines($mixed, false, true));
    Assert::contains($all, '2026-07-001', 'the full report has the healthy patch');
    Assert::false(strpos($bad, '2026-07-001') !== false, 'the failures-only one does not');
    Assert::contains($bad, '2026-09-001', 'but keeps the one that failed');

    Assert::case('json');
    $json = json_decode(Renderer::json([result(true, [fragment('a.txt', Fragment::APPLIED)])]), true);
    Assert::same(0, $json['exit_code'], 'exit code is in the payload');
    Assert::same(1, count($json['patches']), 'one patch');
    Assert::same('a.txt', $json['patches'][0]['targets'][0]['path'], 'targets carry their path');
    Assert::same('applied', $json['patches'][0]['targets'][0]['state'], 'and their state');
},

];

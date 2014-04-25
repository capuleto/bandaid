<?php

/**
 * @file
 * PHPUnit Tests for Bandaid.
 */

// The test class changed name in Drush 7, so if we're running under Drush 5/6,
// we load a class that defines the new name as a subclass to the old.
if (class_exists('Drush_CommandTestCase', FALSE)) {
  require_once 'oldtest-shim.inc';
}

use Unish\CommandUnishTestCase;

/**
 * Deployotron testing class.
 */
class BandaidCase extends CommandUnishTestCase {
  /**
   * Setup before running any tests.
   */
  public static function setUpBeforeClass() {
    parent::setUpBeforeClass();
    $drush_dir = getenv('HOME') . '/.drush';
    // Copy in the command file, so the sandbox can find it.
    symlink(dirname(dirname(__FILE__)) . '/bandaid.drush.inc', getenv('HOME') . '/.drush/bandaid.drush.inc');
    symlink(dirname(dirname(__FILE__)) . '/bandaid.inc', getenv('HOME') . '/.drush/bandaid.inc');

    // Need to set up git minimally for it to work (else it wont commit).
    exec('git config --global user.email "drush@example.com"');
    exec('git config --global user.name "Bandaid Test cases"');
  }

  /**
   * Setup before each test case.
   */
  public function setUp() {
    // Deployotron needs a site to run in.
    if (!file_exists($this->webroot())) {
      $this->setUpDrupal(1);
    }
    else {
      // Remove modules from previous test runs.
      exec('rm -rf ' . $this->webroot() . '/sites/all/modules/*');
    }

    // Clear drush cache to ensure that it discovers the command.
    $this->drush('cc', array('drush'));
  }

  /**
   * Test the basic patch, tearoff, upgrade, apply cycle.
   */
  public function testBasicFunctionallity1() {
    $workdir = $this->webroot() . '/sites/all/modules';
    $this->drush('dl', array('panels-3.3'), array(), NULL, $workdir);

    // Apply first patch, and check for success.
    $options = array(
      'home' => 'https://drupal.org/node/1985980',
      'reason' => 'For altering of new panes.',
    );
    $patch1_string = 'drupal_alter(\'panels_new_pane\', $pane);';
    $this->assertEmpty($this->grep($patch1_string, $workdir . '/panels'));
    $this->drush('bandaid-patch', array('https://drupal.org/files/issues/panels-new-pane-alter-1985980-5.patch', 'panels'), $options, NULL, $workdir);
    $this->assertNotEmpty($this->grep($patch1_string, $workdir . '/panels'));

    // We should have a yaml file now.
    $this->assertFileExists($workdir . '/panels.yml');

    // And that the patch was added.
    $this->assertFileContains($workdir . '/panels.yml', 'https://drupal.org/files/issues/panels-new-pane-alter-1985980-5.patch');

    $options = array(
      'home' => 'https://drupal.org/node/2098515',
      'reason' => 'To avoid notice.',
    );
    $patch2_string = 'if (!isset($pane->type)) {';
    $this->assertEmpty($this->grep($patch2_string, $workdir . '/panels'));
    $this->drush('bandaid-patch', array('https://drupal.org/files/issues/undefined_property_notices_fix-2098515-2.patch', 'panels'), $options, NULL, $workdir);
    $this->assertNotEmpty($this->grep($patch2_string, $workdir . '/panels'));

    // Check that yaml file has been updated.
    $this->assertFileContains($workdir . '/panels.yml', 'https://drupal.org/files/issues/undefined_property_notices_fix-2098515-2.patch');

    // Add a local modification to the module file.
    $content = file_get_contents($workdir . '/panels/panels.module');
    $content .= "\$var = \"Local modification.\";\n";
    file_put_contents($workdir . '/panels/panels.module', $content);

    // Tearoff the patches and check that they're gone.
    $this->drush('bandaid-tearoff', array('panels'), array(), NULL, $workdir);
    $this->assertEmpty($this->grep($patch1_string, $workdir . '/panels'));
    $this->assertEmpty($this->grep($patch2_string, $workdir . '/panels'));
    $this->assertEmpty($this->grep('\$var = \"Local modification.\";', $workdir . '/panels'));

    $local_patch = $workdir . '/panels.local.patch';
    // Ensure that we got a local patch file and it contains the expected.
    $this->assertFileExists($local_patch);
    // We'd like to use a nowdoc instead of a heredoc, but Drush 5 supports PHP
    // 5.2.
    $expected_diff = <<<EOF
diff --git a/panels.module b/panels.module
index dcc13a6..82efc4a 100644
--- a/panels.module
+++ b/panels.module
@@ -1757,3 +1757,4 @@ function panels_preprocess_html(&\$vars) {
     \$vars['classes_array'][] = check_plain(\$panel_body_css['body_classes_to_add']);
   }
 }
+\$var = "Local modification.";

EOF;
    $this->assertEquals($expected_diff, file_get_contents($local_patch));

    // Upgrade panels.
    $this->drush('dl', array('panels-3.4'), array('y' => TRUE), NULL, $workdir);

    // Reapply patches.
    $this->drush('bandaid-apply', array('panels'), array(), NULL, $workdir);

    // The local patch file should be gone.
    $this->assertFalse(file_exists($local_patch));

    // And the project should contain the contents of the patches.
    $this->assertNotEmpty($this->grep($patch1_string, $workdir . '/panels'));
    $this->assertNotEmpty($this->grep($patch2_string, $workdir . '/panels'));
    $this->assertNotEmpty($this->grep('\$var = \"Local modification.\";', $workdir . '/panels'));

  }

  /**
   * Test the basic patch, tearoff, upgrade, apply cycle.
   *
   * This time with a module that has LICENSE.txt committed and the d.o info
   * line in the info file.
   */
  public function testBasicFunctionallity2() {
    $workdir = $this->webroot() . '/sites/all/modules';
    $this->drush('dl', array('exif_custom-1.13'), array(), NULL, $workdir);

    // Apply a patch, and check for success.
    $options = array(
      'home' => 'https://drupal.org/node/2112241',
      'reason' => 'Allow for overriding when uploading multiple images.',
    );
    $patch1_string = 'if(arg(3) == \'edit-multiple\'){return;}';
    $this->assertEmpty($this->grep($patch1_string, $workdir . '/exif_custom'));
    $this->drush('bandaid-patch', array('https://drupal.org/files/exif_override_multiple_images-2112241-1.patch', 'exif_custom'), $options, NULL, $workdir);
    $this->assertNotEmpty($this->grep($patch1_string, $workdir . '/exif_custom'));

    // We should have a yaml file now.
    $this->assertFileExists($workdir . '/exif_custom.yml');

    // And that the patch was added.
    $this->assertFileContains($workdir . '/exif_custom.yml', 'https://drupal.org/files/exif_override_multiple_images-2112241-1.patch');

    // Add a local modification to the module file (we're prepending as they're
    // happening too much at the end of the file).
    $content = file_get_contents($workdir . '/exif_custom/exif_custom.module');
    $content = "\$var = \"Local modification.\";\n" . $content;
    file_put_contents($workdir . '/exif_custom/exif_custom.module', $content);

    // Tearoff the patches and check that they're gone.
    $this->drush('bandaid-tearoff', array('exif_custom'), array(), NULL, $workdir);
    $this->assertEmpty($this->grep($patch1_string, $workdir . '/exif_custom'));
    $this->assertEmpty($this->grep('\$var = \"Local modification.\";', $workdir . '/exif_custom'));

    $local_patch = $workdir . '/exif_custom.local.patch';
    // Ensure that we got a local patch file and it contains the expected.
    $this->assertFileExists($local_patch);
    // We'd like to use a nowdoc instead of a heredoc, but Drush 5 supports PHP
    // 5.2.
    $expected_diff = <<<EOF
diff --git a/exif_custom.module b/exif_custom.module
index c2bdee6..b889d52 100644
--- a/exif_custom.module
+++ b/exif_custom.module
@@ -1,3 +1,4 @@
+\$var = "Local modification.";
 <?php

 /**

EOF;
    $this->assertEquals($expected_diff, file_get_contents($local_patch));

    // Upgrade panels.
    $this->drush('dl', array('exif_custom-1.14'), array('y' => TRUE), NULL, $workdir);

    // Reapply patches.
    $this->drush('bandaid-apply', array('exif_custom'), array(), NULL, $workdir);

    // The local patch file should be gone.
    $this->assertFalse(file_exists($local_patch));

    // And the project should contain the contents of the patches.
    $this->assertNotEmpty($this->grep($patch1_string, $workdir . '/exif_custom'));
    $this->assertNotEmpty($this->grep('\$var = \"Local modification.\";', $workdir . '/exif_custom'));
  }

  /**
   * We should get an error message when patching fails.
   */
  public function testPatchErrorMessage() {
    $workdir = $this->webroot() . '/sites/all/modules';
    // We use exif_custom for this test.
    $this->drush('dl', array('exif_custom-1.13'), array(), NULL, $workdir);

    // Try to patch it with a panels patch, that's sure to fail.
    $options = array(
      'home' => 'https://drupal.org/node/1985980',
      'reason' => 'For altering of new panes.',
    );
    $patch1_string = 'drupal_alter(\'panels_new_pane\', $pane);';
    $this->assertEmpty($this->grep($patch1_string, $workdir . '/exif_custom'));
    $this->drush('bandaid-patch 2>&1', array('https://drupal.org/files/issues/panels-new-pane-alter-1985980-5.patch', 'exif_custom'), $options, NULL, $workdir, self::EXIT_ERROR);
    $this->assertRegExp('/PATCHING_FAILED/', $this->getOutput());
    $this->assertEmpty($this->grep($patch1_string, $workdir . '/exif_custom'));
  }

  /**
   * Test that a dev release is properly detected, and that patch skipping
   * works.
   */
  public function testDevPatching() {
    $workdir = $this->webroot() . '/sites/all/modules';
    // This one is a bit more involved. As dev releases are inherrently
    // unstable, we can't use a real one for testing, so we fake one instead.
    $cwd = getcwd();
    chdir($workdir);
    exec('git clone ssh://git@git.drupal.org/project/snapengage');
    chdir('snapengage');
    // This is a commit 2 commits after the 7.x-1.1 release.
    exec('git checkout 05fe01719cc07cbad6e9e19d123055dae3b435ed');
    // Ungittyfy.
    exec('rm -rf .git');

    // Fudge the info file.
    $info = file_get_contents('snapengage.info');
    $info .= <<<EOF

  ; Information added by drupal.org packaging script on 0000-00-00
version = "7.x-1.1+2-dev"
core = "7.x"
project = "snapengage"
datestamp = "0000000000"
EOF;
    file_put_contents('snapengage.info', $info);
    chdir($cwd);

    // Apply a patch, and check for success.
    $options = array(
      'home' => 'https://drupal.org/node/1916982',
      'reason' => 'Panels support.',
    );
    $patch1_string = "Plugin to handle the 'snapengage_widget' content type";
    $this->assertEmpty($this->grep($patch1_string, $workdir . '/snapengage'));
    $this->drush('bandaid-patch', array('https://drupal.org/files/snapengage-panels-integration-1916982-4.patch', 'snapengage'), $options, NULL, $workdir);
    $this->assertNotEmpty($this->grep($patch1_string, $workdir . '/snapengage'));

    // Check that the yaml file has been updated.
    $this->assertFileContains($workdir . '/snapengage.yml', 'https://drupal.org/files/snapengage-panels-integration-1916982-4.patch');

    // Apply another patch, and check for success.
    $options = array(
      'home' => 'https://drupal.org/node/1933716',
      'reason' => 'New API.',
    );
    $patch2_string = "If enabled this allowes you to use the advanced features.";
    $this->assertEmpty($this->grep($patch2_string, $workdir . '/snapengage'));
    $this->drush('bandaid-patch', array('https://drupal.org/files/snapengage-integrate-new-api-code.patch', 'snapengage'), $options, NULL, $workdir);
    $this->assertNotEmpty($this->grep($patch2_string, $workdir . '/snapengage'));

    // Check that the yaml file has been updated.
    $this->assertFileContains($workdir . '/snapengage.yml', 'https://drupal.org/files/snapengage-integrate-new-api-code.patch');

    // Tearoff the patches and check that they're gone.
    $this->drush('bandaid-tearoff', array('snapengage'), array(), NULL, $workdir);
    $this->log($this->getOutput());
    $this->assertEmpty($this->grep($patch1_string, $workdir . '/snapengage'));
    $this->assertEmpty($this->grep($patch2_string, $workdir . '/snapengage'));

    // Update module.
    $this->drush('dl', array('snapengage-1.2'), array('y' => TRUE), NULL, $workdir);

    // Check that we fail per default on failing patches.
    $this->drush('bandaid-apply 2>&1', array('snapengage'), array(), NULL, $workdir, self::EXIT_ERROR);

    // Check for the expected error message.
    $this->assertRegExp('/Unable to patch with snapengage-panels-integration-1916982-4.patch/', $this->getOutput());

    // Try again, but now with options to skip it.
    $this->drush('bandaid-apply 2>&1', array('snapengage'), array('ignore-failing' => TRUE, 'update-yaml' => TRUE), NULL, $workdir);

    // Check output.
    $this->assertRegExp('/Unable to patch with snapengage-panels-integration-1916982-4.patch/', $this->getOutput());
    $this->assertRegExp('/Updated yaml file./', $this->getOutput());

    // Check that it has been properly patched.
    $this->assertNotEmpty($this->grep($patch1_string, $workdir . '/snapengage'));
    $this->assertNotEmpty($this->grep($patch2_string, $workdir . '/snapengage'));
    // Check that the yaml file contains the right patches.
    $this->assertFileNotContains($workdir . '/snapengage.yml', 'https://drupal.org/files/snapengage-panels-integration-1916982-4.patch');
    $this->assertFileContains($workdir . '/snapengage.yml', 'https://drupal.org/files/snapengage-integrate-new-api-code.patch');

    $this->log(file_get_contents($workdir . '/snapengage.yml'));
  }

  /**
   * Grep for a string.
   */
  protected function grep($string, $root) {
    exec('grep -r ' . escapeshellarg($string) . ' ' . escapeshellarg($root), $output, $rc);
    if ($rc > 1) {
      $this->fail("Error grepping.");
    }
    return implode("\n", $output);
  }

  /**
   * Assert that file contains a given string.
   */
  protected function assertFileContains($file, $string) {
    $this->assertContains($string, file_get_contents($file));
  }

  /**
   * Assert that file contains a given string.
   */
  protected function assertFileNotContains($file, $string) {
    $this->assertNotContains($string, file_get_contents($file));
  }
}

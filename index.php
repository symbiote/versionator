<?php

include_once __DIR__ . '/vendor/autoload.php';

use Packagist\Api\Result\Package\Version;
use GitWrapper\GitWrapper;

// The list of managed modules. A key/value map of 
// module_name => max_version_checked
// If no key is set, it is presumed that there's no max to check against, and that the value is the module
// name. 
// If the key is set, and the max_version supplied, versions up to the provided value are compared against; greater 
// than that are ignored. This is to handle cases where some projects have branches labelled after the SS version, 
// eg Better Buttons. 
$managed = array();

// These are set to the ba-sis modules by default.
if (file_exists(__DIR__.'/managed_modules.php')) {
	$managed = include __DIR__.'/managed_modules.php';
}

$remapped = array();
foreach ($managed as $key => $value) {
	if (is_int($key)) {
		$remapped[$value] = true;
	} else {
		$remapped[$key] = $value;
	}
}

$managed = $remapped;

$version_overrides = array();
if (file_exists(__DIR__.'/version_overrides.php')) {
	$version_overrides = include __DIR__.'/version_overrides.php';
}

echo "\n";

$options = getopt('f:w:', array(
	'timeout::',
	'version-fix::',
	'all-modules::'
));

if (!isset($options['f'])) {
	exit("Specify a composer file to check.\n");
}

$file = realpath($options['f']);
if (!file_exists($file)) {
	o("Could not read $file\n");
	exit();
}

$workspace = isset($options['w']) ? $options['w'] : __DIR__ . '/workspace';

$composer = json_decode(file_get_contents($file));

if (!$composer) {
	o("Could not parse composer details\n");
	exit();
}

if (!isset($composer->require)) {
	o("No requirements found, enjoy!\n");
	exit();
}

$client = new Packagist\Api\Client();

$wrapper = new GitWrapper();
$time = isset($options['timeout']) ? intval($options['timeout']) : 120;
$wrapper->setTimeout($time);

$notices = array();

// Determine whether any project module dependencies should be locked down to a specific tag.

if(isset($options['version-fix']) && $options['version-fix']) {
	$modules = explode(',', $options['version-fix']);
	foreach($modules as $module) {

		// Determine the dependencies of each module.

		$project = substr($file, 0, strlen($file) - 13);
		$moduleComposer = "{$module}/composer.json";
		$moduleComposerPath = "{$project}{$moduleComposer}";
		if(!file_exists($moduleComposerPath) || !($dependencies = json_decode(file_get_contents($moduleComposerPath)))) {
			o("Could not read/parse {$moduleComposer} for version fixing\n");
			exit();
		}
		if(!isset($dependencies->require)) {
			o("No requirements found, enjoy!\n");
			exit();
		}

		// Check each requirement version that matches the ~X.X syntax.

		foreach($dependencies->require as $packageName => $requiredVersion) {
			if(($packageName === 'php') || !preg_match('#^\~[1-9]\.[0-9]\Z#', $requiredVersion)) {
				continue;
			}

			// Retrieve the module from packagist.

			$package = retrieve_package($packageName, $requiredVersion, $client);
			if(!$package) {
				continue;
			}

			// Retrieve the module versions.

			foreach($package->getVersions() as $version) {

				// Make sure the version is a valid tag.

				$latest = $version->getVersion();
				if(!preg_match('#^[1-9]\.[0-9]\.[0-9][0-9]?\Z#', $latest)) {
					continue;
				}

				// The most recent tags are always found first, therfore we don't need to determine the latest.

				else if(strpos($latest, substr($requiredVersion, 1, strrpos($requiredVersion, '.'))) === 0) {

					// Output the latest tag that matches the requirement version.

					o("\033[32m✔ The latest tag is $latest\n\033[0m");
					break;
				}
			}
		}
	}
}

// Determine whether any project modules have an updated tag available, or whether they need to be tagged.

else {

	// Check each requirement version.

	foreach ($composer->require as $packageName => $requiredVersion) {
		if (($packageName === 'php') || (!isset($options['all-modules']) && !isset($managed[$packageName]))) {
			continue;
		}
		$package_name = basename($packageName);

		// Retrieve the module from packagist.

		$package = retrieve_package($packageName, $requiredVersion, $client);
		if(!$package) {
			continue;
		}

		$latestVersion = 0;
		$repo = null;
		$latestRef = null;

		foreach ($package->getVersions() as $version) {
			/* @var $version Version */
			$source = $version->getSource();

			if (!isset($options['all-modules']) && ($maxVersion = $managed[$packageName]) && !is_bool($maxVersion)) {
				// if we're higher than the max version checked, we'll bail
				$greaterThan = version_compare($version->getVersion(), $maxVersion);
				if ($greaterThan > 0) {
					continue;
				}
			}

			$comp = version_compare($version->getVersion(), $latestVersion);
	//		o("Comparing " . $version->getVersion() . " to $latestVersion : $comp");
			if (version_compare($version->getVersion(), $latestVersion) > 0) {
				$latestVersion = $version->getVersion();
				$repo = $version->getSource()->getUrl();
				$latestRef = $version->getSource()->getReference();
			}
			if (!$latestRef) {
				$latestRef = $version->getSource()->getReference();
			}
		}


		if (version_compare($latestVersion, $requiredVersion) > 0 && $latestVersion) {
			$notices[] = "\033[31m✘ $packageName has an updated, fixed tag version available $latestVersion (currently $requiredVersion)\033[0m";
		} else if (!$latestVersion) {
			$notices[] = "\033[31m✘ $packageName has no proper version available\033[0m";
		} else {
			$notices[] = "\033[32m✔ $packageName looks to be using the latest available version\033[0m";
		}

		// now check out the project
		if ($repo && $latestRef) {
			$path = $workspace . DIRECTORY_SEPARATOR . basename($package_name);
			if (!file_exists($path)) {
				$git = $wrapper->cloneRepository($source->getUrl(), $workspace . DIRECTORY_SEPARATOR . basename($package_name));
			} else {
				$git = $wrapper->workingCopy($path);
				$git->checkout('master', array('f' => true));
				$git->pull();
				$git->fetch(array('tags' => true));
			}

			$git->clearOutput();
			// checkout the 'latest' ref as far as packagist knows about, then check to see
			// whether the repository has changes between it and the current master HEAD
			// $git->diff($latestRef, array('name-status' => true));
			$git->log($latestRef . '..master', array('pretty' => 'oneline', 'abbrev-commit' => true));
			$out = trim($git->getOutput());

			if (strlen($out)) {
				$lines = explode("\n", $out);
				if (count($lines) > 0) {
					$notices[] = "\033[31m✘ $packageName: " . count($lines) . " commits found between $latestVersion ($latestRef) and master\033[0m";
					$notices[] = "$out\n";
				}
			} else {
				$notices[] = "\033[32m✔ $packageName @ $latestVersion appears to be up-to-date\033[0m\n";
			}
		}
	}
}

foreach ($notices as $notice) {
	o($notice);
}

function o($txt) {
	echo "$txt\n";
}

function retrieve_package($packageName, $requiredVersion, $client) {

	o("Checking requirement $packageName $requiredVersion");

	try {
		$package = $client->get($packageName);
	}
	catch(Exception $e) {
		o("\033[31m✘ The package could not be found\n\033[0m");
		return false;
	}

	o("Retrieved package " . $package->getName() . "\n");
	return $package;
}

function del_tree($dir) {
	$files = array_diff(scandir($dir), array('.', '..'));
	foreach ($files as $file) {
		(is_dir("$dir/$file") && !is_link($dir)) ? del_tree("$dir/$file") : unlink("$dir/$file");
	}
	return rmdir($dir);
}

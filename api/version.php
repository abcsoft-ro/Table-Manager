<?php
/**
 * API: versiunea aplicatiei care ruleaza pe aceasta stație.
 *
 * GET -> versiune human (VERSION), SHA commit, numar de build, branch, date,
 *        sursa (setup/update/dev) + cateva informatii de mediu (PHP, host,
 *        folder). Nu se conecteaza la baza de date si nu expune secrete.
 *
 * Util pentru depanare: se poate interoga remote de la alt post:
 *   http://<statie>/rual/api/version.php
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/version_common.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

$info = appVersionInfo();
$folder = basename(appVersionRoot());

sendJsonResponse([
    "status"      => "success",
    "version"     => $info['version'],
    "commit"      => $info['commit'],
    "short"       => $info['short'],
    "build"       => $info['build'],
    "branch"      => $info['branch'],
    "builtAt"     => $info['builtAt'],
    "updatedAt"   => $info['updatedAt'],
    "source"      => $info['source'],
    "label"       => appVersionLabel(),
    "php"         => PHP_VERSION,
    "host"        => gethostname() ?: '',
    "folder"      => $folder,
]);

#!/usr/bin/php7.0
<?php
require_once __DIR__ . '/vendor/autoload.php';

function base64_decode_urlsafe($data)
{
  return base64_decode(strtr($data, '-_', '+/')); // dont worry about replacing equals sign
}

if (php_sapi_name() != 'cli')
{
  echo 'This application must be run on the command line.';
  exit(1);
}

function indent($text, $prefix) {
  return implode("\n", array_map(function($line) use($prefix) {return $line ? ($prefix.$line) : '';}, explode("\n", $text)));
}

/**
 * Returns an authorized API client.
 * @return Google_Client the authorized client object
 */
function getGoogleClient()
{
  $client = new Google_Client();
  $client->setApplicationName('morningdj');
  $client->setAuthConfig(__DIR__ . '/client_secret.json');
  $client->setAccessType('offline');

  // If modifying these scopes, delete the existing credentials file
  $client->setScopes(implode(' ', [Google_Service_Gmail::GMAIL_READONLY]));

  // Load previously authorized credentials from a file.
  $credentialsPath = __DIR__ . '/credentials.json';
  if (file_exists($credentialsPath))
  {
    $accessToken = json_decode(file_get_contents($credentialsPath), true);
  }
  else
  {
    // Request authorization from the user.
    $authUrl = $client->createAuthUrl();
    printf("Open the following link in your browser:\n%s\n", $authUrl);
    print 'Enter verification code: ';
    $authCode = trim(fgets(STDIN));

    // Exchange authorization code for an access token.
    $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

    // Store the credentials to disk.
    file_put_contents($credentialsPath, json_encode($accessToken));
    printf("Credentials saved to %s\n", $credentialsPath);
  }

  $client->setAccessToken($accessToken);

  // Refresh the token if it's expired.
  if ($client->isAccessTokenExpired())
  {
    $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
    $newToken = $client->getAccessToken();
    if (!isset($newToken['refresh_token']) && isset($accessToken['refresh_token']))
    {
      $newToken['refresh_token'] = $accessToken['refresh_token'];
    }
    file_put_contents($credentialsPath, json_encode($newToken));
  }
  return $client;
}

function get_inner_html( $node ) {
  $innerHTML= '';
  $children = $node->childNodes;
  foreach ($children as $child) {
    $innerHTML .= $child->ownerDocument->saveXML( $child );
  }
  return $innerHTML;
}

function getDateStr($message) {
  return date('l, F jS', substr($message->getInternalDate(), 0, -3));
}

function fixDangling($text) {
  $pos = strpos($text, ' ');
  if ($pos === false) {
    return $text;
  }
  $text = substr_replace($text, '&nbsp;', $pos, 1);

  $pos = strrpos($text, ' ');
  if ($pos === false) {
    return $text;
  }
  return substr_replace($text, '&nbsp;', $pos, 1);
}

// Get the API client and construct the service object.
$service = new Google_Service_Gmail(getGoogleClient());

$user             = 'me';
$mostRecentExport = null;

$output= '';

// Search for export in messages
$pageToken = null;
do {
  $messageSearch = $service->users_messages->listUsersMessages($user, ['q' => 'subject:"Morning DJ"', 'pageToken' => $pageToken]);
  $pageToken = $messageSearch->getNextPageToken();

foreach ($messageSearch->getMessages() as $messageData)
{
  $message = $service->users_messages->get($user, $messageData->getId());
  $dayOutput = '';
  foreach($message->getPayload()->getParts() as $part)
  {
    if ($part->getMimeType() == 'text/html')
    {
      $html = base64_decode(strtr($part->getBody()->getData(), '-_', '+/')) . "\n";
      $dom = new DOMDocument();
      $dom->loadHTML($html);
      $links = $dom->getElementsByTagName('a');
      foreach($links as $link)
      {
        $href = $link->getAttribute('href');
        if (stripos($href, 'youtu') === false) continue;
        $linkText = fixDangling(strip_tags(get_inner_html($link)));
        if (count(explode(' ', $linkText)) < 2) {
          list($code, $out, $err) = \lyoshenka\Shell::exec("youtube-dl --get-filename -o '%(title)s' $href");
          $linkText = fixDangling(htmlentities(trim($out)));
        }
        $dayOutput .= '<li><a href="' . $href . '">' . $linkText . "</a></li>\n";
      }
    }
  }
  if ($dayOutput)
  {
    $output .= '<div><h4>' . getDateStr($message) . "</h4><ul>\n" . $dayOutput . "</ul></div>\n\n\n";
  }
}

} while($pageToken);

$template = <<<EOF
<html>
<head>
  <link rel="stylesheet" href="/css/style.css"></link>
</head>
<body>
  <div class="page-wrapper">
    <section class="intro">
      <header>
        <h1>
          <div class="dj-name">
            Nick Purifico
          </div>
          Morning DJ
        </h1>
        <h2>The Cure for Your Mundane Morning</h2>
      </header>
    </section>

    <div class="main-content">
      <div class="wrapper">

        <div class="playlist">
          <h3>The Playlist</h3>
          <div class="playlist-tracks">

CONTENT_PLACEHOLDER

          </div>
        </div>

        <div class="bottom">
          <nav>
            <ul>
              <li class="next">
                <a href="https://open.spotify.com/user/skawhore/playlist/1ik4ovIt7KXF9JQyPppACr">
                  <span class="indicator">&rsaquo;</span>
                </a>
              </li>
              <li class="viewall">
                <a href="https://open.spotify.com/user/skawhore/playlist/1ik4ovIt7KXF9JQyPppACr">Hear it on Spotify</a>
              </li>
            </ul>
          </nav>
        </div>
      </div>
    </div>

    <footer>
      By <a href="https://grin.io">Grin</a>. Design from <a href="http://www.csszengarden.com/218/">CSS Zen Garden</a>.
    </footer>
  </div>
</body>
</html>
EOF;

$content = str_replace('CONTENT_PLACEHOLDER', indent($output, '            '), $template);

file_put_contents(__DIR__.'/index2.html', $content);

exit(0);

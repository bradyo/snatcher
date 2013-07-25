#!/bin/php
<?php
/*
 * REQUIREMENTS:
 * general requirements: php5-cgi, php5-curl
 * required for compiling rtmpdump: libssl-dev
 * required for converting flv audio to mp3: ffmpeg, lame
 *
 * 
 * HOW TO RUN:
 * cd into the stripper directory
 * execute: php stripper.php <myspace_username>
 *
 * <myspace_username> is the name of the band as appears in the myspace url.
 * for example, to rip "http://www.myspace.com/chairlift", execute
 * php stripper.php chairlift
 *
 * The program will create a directory named after the <myspace_username> to
 * put all the output files
 */

if ( ! isset($argv[1])) {
  die("you must supply myspace page, i.e.: php stripper.php slayer\n");
}

$band = $argv[1];
if (is_dir($band)) {
  die("directory '$band' already exists\n"); 
}

$params = getPlaylistParams($band);
if ($params === null) {
  echo "could not extract flash params (friendid and plid) from html page\n";
}

$friendId = $params['friendId'];
$playlistId = $params['playlistId'];
$songs = getSongs($friendId, $playlistId);
if (count($songs) < 1) {
  echo "no songs for friendId = $friendId and playlistId = $playlistId\n";
}

mkdir($band);
foreach ($songs as $song) {
  $songData = getSongData($song['albumId'], $song['songId']);
  $title = $songData['title'];
  $rtmp = $songData['rtmp'];
  $rtmp = str_replace('rtmp:', 'rtmpe:', $rtmp);
  $basename = "$band/$title";
  echo "processing $basename\n\n";

  // pull the file using rtmpdump
  echo "attempting stream dump on song:\n";
  $flvFilename = $basename.'.flv';
  $command = "./rtmpdump/rtmpdump -r $rtmp -o \"$flvFilename\"";
  echo "$command\n";
  exec($command);

  $success = (file_exists($flvFilename) && filesize($flvFilename) > 0);
  if ( ! $success)  {
    echo "failed to create flv file '$flvFilename'\n";
    continue;
  }

  // the file was created successfully, we need to convert to mp3
  // convert to wav, and then to mp3 with ffmpeg and lame
  $wavFilename = $basename.'.wav';
  echo "attempting convert flv to wav:\n";
  $command = "ffmpeg -i \"$flvFilename\" -acodec pcm_s16le -ac 2 -ab 128k -vn -y \"$wavFilename\"";
  echo "$command\n";
  exec($command);

  $success = (file_exists($wavFilename) && filesize($wavFilename) > 0);
  if (! $success) {
    echo "failed to create wav file '$wavFilename'\n";
    continue;
  }

  // convert to mp3 with lame
  $mp3Filename = $basename.'.mp3';
  echo "attempting to convert wav to mp3:\n";
  $command = "lame --preset cd \"$wavFilename\" \"$mp3Filename\"";
  echo "$command\n";
  exec($command);

  $success = (file_exists($mp3Filename) && filesize($mp3Filename) > 0);
  if ( ! $success)  {
    echo "failed to create mp3 file '$mp3Filename'\n";
    continue;
  }

  if ($success) {
    echo "successfully ripped: '$mp3Filename'\n";
  }

  echo "\n\n";
}

// perform a little clean up
echo "cleaning up...\n";
$tempFolder = $band.'/temp';
mkdir($tempFolder);
exec("mv \"$band\"/*.flv \"$tempFolder/\"");
exec("mv \"$band\"/*.wav \"$tempFolder/\"");


// =============================================================================
// END SCRIPT  o==|:::::::::::::>
// =============================================================================


function getPlaylistParams($band)
{
  $url = 'http://www.myspace.com/'.$band;
  $html = getContent($url);

  $pattern = '/<param name="flashvars" value="(.+)" \/>/';
  $matches = array();
  preg_match_all($pattern, $html, $matches);

  $friendId = null;
  $playlistId = null;
  if (count($matches[0]) > 0) {
    $input = join('&', $matches[1]);

    $params = array();
    parse_str($input, $params);

    if (isset($params['plid'])) {
      $playlistId = $params['plid'];
    }
    if (isset($params['profid'])) {
      $friendId = $params['profid'];
    }
  }

  $playlistParams = null;
  if ($friendId !== null && $playlistId !== null) {
    $playlistParams = array('friendId' => $friendId, 'playlistId' => $playlistId);
  }
  return $playlistParams;
}


function getContent($url)
{
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HEADER, 0); 
  curl_setopt($ch, CURL_USERAGENT,
    'Firefox (WindowsXP) – Mozilla/5.0 (Windows; U; Windows NT 5.1; en-GB; rv:1.8.1.6)'
    .' Gecko/20070725 Firefox/2.0.0.6'
  );
  $content = curl_exec ($ch);
  curl_close ($ch);
  return $content;
}


function getSongs($friendId, $playlistId)
{
  $script = 'http://musicservices.myspace.com/Modules/MusicServices/Services/MusicPlayerService.ashx';
  $params = array(
    'action=getPlaylist',
    'friendId='.$friendId,
    'playlistId='.$playlistId
  );
  $xmlData = getContent($script.'?'.join('&', $params));
  $rootElement = new SimpleXMLElement($xmlData);

  $songs = array();
  foreach ($rootElement->trackList->track as $trackElement) {
    $songElement = $trackElement->song[0];

    $attributes = $songElement->attributes();
    $songId = (isset($attributes['songId'])) ? (string)$attributes['songId'] : null;
    $albumId = (isset($attributes['albumId'])) ? (string)$attributes['albumId'] : null;

    if ($songId !== null && $albumId !== null) {
      $songs[] = array('songId' => $songId, 'albumId' => $albumId);
    }
  }
  return $songs;
}


function getSongData($albumId, $songId)
{
  $script = 'http://musicservices.myspace.com/Modules/MusicServices/Services/MusicPlayerService.ashx';
  $params = array(
    'action=getSong',
    'albumId='.$albumId,
    'ptype=4',
    'sample=0',
    'songId='.$songId
  );
  $xmlData = getContent($script.'?'.join('&', $params));

  $xmlElement = new SimpleXmlElement($xmlData, LIBXML_NOCDATA);
  $track = $xmlElement->trackList[0]->track[0];
  $title = (string)$track->title[0];
  $rtmp = (string)$track->rtmp[0];
  
  return array('title' => $title, 'rtmp' => $rtmp);
}

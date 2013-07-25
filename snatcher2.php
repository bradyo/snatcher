#!/bin/php
<?php
/*
 * REQUIREMENTS:
 * general requirements: php5-cgi, php5-curl
 * required for compiling rtmpdump: libssl-dev
 * required for converting flv audio to mp3: ffmpeg, lame
 * 
 * SETUP:
 * install the required libraries and programs, drop rtmpdump binary 
 * (http://rtmpdump.mplayerhq.hu/) in ./rtmpdump/ directory
 * 
 * HOW TO RUN:
 * visit your band's myspace music page and select the desired album. execute:
 * php snatcher.php 'http://www.myspace.com/infectedmushroomcentral/music/albums/legend-of-the-black-shawarma-15301882'
 * 
 * All the album music files will be cached locally in mp3 format.
 */

if ( ! isset($argv[1])) {
  die("you must supply myspace music url\n");
}

$pageUrl = $argv[1];
$songs = getSongs($pageUrl);
if (count($songs) < 1) {
  echo "no songs for url\n";
}

foreach ($songs as $i => $song) {
  $trackNumber = $i + 1;
  $songName = html_entity_decode($song['song_name'], ENT_QUOTES, "UTF-8");
  $songName = str_replace('/', '-', $songName);

  $albumName = html_entity_decode($song['album_name'], ENT_QUOTES, "UTF-8");
  $artistName = html_entity_decode($song['artist_name'], ENT_QUOTES, "UTF-8");

  $songData = getSongData($song['albumId'], $song['songId']);
  $rtmp = $songData['rtmp'];
  $rtmp = str_replace('rtmp:', 'rtmpe:', $rtmp);

  $dir = str_replace('/', '-', $artistName).'/'.str_replace('/', '-', $albumName);
  if (!file_exists($dir)) {
    mkdir($dir, 0777, true);
  }

  $basename = $dir.'/'.sprintf("%02d", $trackNumber).' - '.$songName;
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
  $command = "lame --preset cd \"$wavFilename\" \"$mp3Filename\" "
		. "--tt \"$songName\" --ta \"$artistName\" --tl \"$albumName\" --tn $trackNumber";
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

  exec("rm \"$flvFilename\"");
  exec("rm \"$wavFilename\"");

  echo "\n\n";
}

function getSongs($url)
{
  $html = getContent($url);
  $html = preg_replace('/\s+/', ' ', $html);

  $pattern = '/<li class="msaaSongEntry.+?>/';
  $matches = array();
  preg_match_all($pattern, $html, $matches);

  $songs = array();
  foreach ($matches[0] as $match) {
    $subMatches = array();
    preg_match_all('/(.+?)=\"(.*?)\"/', $match, $subMatches);
    $keys = array_map('trim', $subMatches[1]);
    $values = array_map('trim', $subMatches[2]);
    
    $songId = null;
    $albumId = null;
    $artistId = null;
    $songName = null;
    $albumName = null;
    $artistName = null;
    foreach ($keys as $i => $key) {
      if ($key == 'msm_songid') {
        $songId = $values[$i];
      }
      if ($key == 'msm_albumid') {
        $albumId = $values[$i];
      }
      if ($key == 'msm_artistid') {
        $artistId = $values[$i];
      }
      if ($key == 'msm_songname') {
        $songName = $values[$i];
      }
      if ($key == 'msm_albumname') {
        $albumName = $values[$i];
      }
      if ($key == 'msm_artistname') {
        $artistName = $values[$i];
      }
    }

    if ($songId !== null && $albumId !== null) {
      $songData = array('songId' => $songId, 'albumId' => $albumId);
      if ($songName !== null) {
        $songData['song_name'] = $songName;
      }
      if ($albumName !== null) {
        $songData['album_name'] = $albumName;
      }
      if ($artistName !== null) {
        $songData['artist_name'] = $artistName;
      }
      $songs[] = $songData;
    }
  }
  return $songs;
}


function getContent($url)
{
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HEADER, 0); 
  curl_setopt($ch, CURLOPT_USERAGENT,
    'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 6.1; '
		. 'Trident/4.0; GTB6.5; SLCC2; .NET CLR 2.0.50727; .NET CLR 3.5.30729; .'
  );
  $content = curl_exec ($ch);
  curl_close ($ch);
  return $content;
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

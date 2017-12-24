<?php
/**
* CCHits.net is a website designed to promote Creative Commons Music,
* the artists who produce it and anyone or anywhere that plays it.
* These files are used to generate the site.
*
* PHP version 5
*
* @category Default
* @package  CCHitsClass
* @author   Jon Spriggs <jon@sprig.gs>
* @license  http://www.gnu.org/licenses/agpl.html AGPLv3
* @link     http://cchits.net Actual web service
* @link     https://github.com/CCHits/Website/wiki Developers Web Site
* @link     https://github.com/CCHits/Website Version Control Service
*/

class debugout {
  private static $output = "";
  private static $dumpset = false;

  public static function add($message) {
    if (static::$output !== "") {
      static::$output .= PHP_EOL . $message;
    } else {
      static::$output = $message;
    }
    if (static::$dumpset) {
      static::dump();
    }
  }

  public static function dump($message = "") {
    static::$dumpset = true;
    if ($message !== "") {
      static::add($message);
    }
    echo static::$output . PHP_EOL;
    static::reset(false);
  }

  public static function reset($dumpset = true) {
    static::$output = "";
    if ($dumpset) {
      static::$dumpset = false;
    }
  }
}


/**
 * This function will, eventually, do things like:
 * - post to StatusNet that the show is generated
 * - update the system with the generated files
 * - add timestamps and hashes
 *
 * @param integer $show_id     The ShowID to finalize
 * @param string  $show_root   The base path to the files we'll be uploading.
 * @param string  $comment_url The URL of the appropriate point to comment on this show
 * @param string  $showtype    The type of show to upload and finalize
 * @param integer $showdate    The date of the show to upload and finalize (as yyyymm or yyyymmdd)
 *
 * @return void
 */
function finalize($show_id, $show_root, $comment_url, $json_layout, $showtype, $showdate)
{
    $array = array('hash' => '', 'time' => '', 'comment' => $comment_url, 'jsonAudioLayout' => $json_layout);
    $success = true;
    $finalize_url = Configuration::getAPI() . '/finalize/';
    $split_url = Configuration::getAPI() . '/split/';
    if (file_exists($show_root . 'mp3')) {
        $array['hash'] .= 'mp3:' . md5_file($show_root . 'mp3');
        runSFTP($show_root, $showtype, $showdate, 'mp3');
        $array['time'] = getTrackLength($show_root . 'mp3');
        $data = curlPostRequest($finalize_url . $show_id, $array);
        if ($data[0] == false) {
            debugout::dump("Failed to finalize MP3.");
        }
    }
    if (file_exists($show_root . 'oga')) {
        $array['hash'] .= 'oga:' . md5_file($show_root . 'oga');
        runSFTP($show_root, $showtype, $showdate, 'oga');
        if ($array['time'] == '') {
            // Why we don't have the time set after the MP3 file is done, I don't know
            // but just to be on the safe side...
            $array['time'] = getTrackLength($show_root . 'oga');
        }
        $data = curlPostRequest($finalize_url . $show_id, $array);
        if ($data[0] == false) {
            debugout::dump("Failed to finalize OGA.");
        }
    }
    if (file_exists($show_root . 'm4a')) {
        $array['hash'] .= 'm4a:' . md5_file($show_root . 'm4a');
        runSFTP($show_root, $showtype, $showdate, 'm4a');
        // I can't recall whether soxi will get m4a track lengths. To be on the safe side
        // don't bother. It should have been picked up in the mp3 and oga files anyway.
        $data = curlPostRequest($finalize_url . $show_id, $array);
        if ($data[0] == false) {
            debugout::dump("Failed to finalize OGA.");
        }
    }
    return $success;
}

/**
 * A function to post StatusNet updates
 * 
 * @param array $array An array of posts to make
 * 
 * @return integer ConversationID or 0 
 */
function updateStatusNet($array = array())
{
    if ((! is_array($array)) || (Configuration::getStatusNetUser() == '')) {
        return 0;
    }
    $conversation = 0;
    $in_reply_to = 0;
    foreach ($array as $post) {
        if ($in_reply_to > 0 && strstr($post, '@' . Configuration::getStatusNetUser())) {
            list($state, $result, $response) = curlPostRequest(Configuration::getStatusNet() . 'update.xml', array('status' => $post, 'in_reply_to_status_id' => $in_reply_to));
        } else {
            list($state, $result, $response) = curlPostRequest(Configuration::getStatusNet() . 'update.xml', array('status' => $post));
        }
        if ($state == true) {
            $atom = curlGetResource(Configuration::getStatusNet() . 'friends_timeline/' . Configuration::getStatusNetUser() . '.atom?count=1', 0);
            if ($atom != false && $in_reply_to == 0) {
                preg_match("/<link rel=\"alternate\" type=\"text\/html\" href=\"[^\"]+\/(\d+)/", $atom, $matches);
                if (count($matches) > 0) {
                    $in_reply_to = $matches[1];
                }
                preg_match("/<link rel=\"ostatus:conversation\" href=\"[^\"]+\/conversation\/(\d+)/", $atom, $matches);
                if (count($matches) > 0) {
                    $conversation = $matches[1];
                }
            }
        }
    }
    return $conversation;
}

/**
 * Provided an array of strings is provided, select one at random and return it
 *
 * @param array $array An array of strings to return.
 *
 * @return string|boolean One string selected at random, or nothing at all.
 */
function randomTextSelect($array)
{
    if (! is_array($array) or count($array) == 0) {
        return false;
    }
    return $array[rand(0, count($array) -1)];
}

/**
 * Given a sable based XML string, encode it to WAV
 *
 * @param xml  $text   The XML string to encode
 * @param path $output The filename to write the generated WAV file to.
 *
 * @return boolean Success or failure
 */
function convertSableXmlToWav($text, $output)
{
    $out = fopen($output . '.sable', 'w');
    if ($out == false) {
        fclose($out);
        return false;
    }
    if (! fwrite($out, $text)) {
        fclose($out);
        return false;
    }
    fclose($out);
    if (file_exists($output . '.sable')) {
        $cmd = 'text2wave -o "' . Configuration::getWorkingDir() . '/tmp.wav' . '" "' . $output . '.sable' . '"';
        if (debugExec($cmd) != 0) {
            if (file_exists($output)) {
                debugUnlink($output);
            }
            return false;
        }
        debugUnlink($output . '.sable');
        if (convertToWavOutput(Configuration::getWorkingDir() . '/tmp.wav', $output)) {
            debugUnlink(Configuration::getWorkingDir() . '/tmp.wav');
            return true;
        } else {
            debugUnlink(Configuration::getWorkingDir() . '/tmp.wav');
            debugUnlink($output);
            return false;
        }
    } else {
        return false;
    }
}

/**
 * This function creates a period of silence.
 *
 * @param float $duration The duration to create of silence
 * @param path  $output   The output path to place the file
 *
 * @return boolean Success or Failure
 */
function generateSilenceWav($duration, $output)
{
    $cmd = 'sox -q -n -r ' . $GLOBALS['RATE'] . ' -c 2 "' . $output . '" trim 0.0 ' . $duration;
    if (debugExec($cmd) != 0) {
        if (file_exists($output)) {
            debugUnlink($output);
        }
        return false;
    }
    return true;
}

/**
 * This function invokes SoX to remove silence at each end of the track
 *
 * @param path $input The file to process and return
 *
 * @return boolean Success or Failure
 */
function trackTrimSilence($input)
{
    $cmd = 'sox "' . $input . '" "' . $input . '.trim2.wav" silence 1 0.1 1% reverse';
    if (debugExec($cmd) != 0) {
        if (file_exists($input . '.trim2.wav')) {
            debugUnlink($input . '.trim2.wav');
        }
        return false;
    } else {
        $cmd = 'sox -r ' . $GLOBALS['RATE'] . ' -c 2 "' . $input . '.trim2.wav" "' . $input . '.trim.wav" silence 1 0.1 1% reverse';
        if (debugExec($cmd) != 0) {
            if (file_exists($input . '.trim.wav')) {
                debugUnlink($input . '.trim.wav');
            }
            return false;
        }
        debugUnlink($input . '.trim2.wav');
        return true;
    }
}

/**
 * This function invokes SoX to place the first track before the second track and return that as the output file, optionally, removing the sources once it's done
 *
 * @param path    $first          The first file to use
 * @param path    $second         The second file to use
 * @param path    $output         The output location of the combined two files
 * @param boolean $remove_sources Whether the script should remove the two source files.
 *
 * @return boolean Success or Failure
 */
function concatenateTracks($first, $second, $output, $remove_sources = true)
{
    if (file_exists($first) and file_exists($second)) {
        $cmd = 'sox -q --combine concatenate -r ' . $GLOBALS['RATE'] . ' -c 2 "' . $first . '" -r ' . $GLOBALS['RATE'] . ' -c 2 "' . $second . '" -r ' . $GLOBALS['RATE'] . ' -c 2 "' . $output . '"';
        if (debugExec($cmd) != 0) {
            if (file_exists($output)) {
                debugUnlink($output);
            }
            // It may be that they wouldn't handle the sample rates - try converting to the desired format forst, and then merge.
            $tmpfname = tempnam(dirname(__FILE__) . '/TEMP', "fileconvert_");
            $cmd1 = 'sox -q "' . $first . '" -r ' . $GLOBALS['RATE'] . ' -c 2 "' . $tmpfname . '.1.wav"';
            $cmd2 = 'sox -q "' . $second . '" -r ' . $GLOBALS['RATE'] . ' -c 2 "' . $tmpfname . '.2.wav"';
            if (debugExec($cmd1) != 0 || debugExec($cmd2) != 0) {
                if (file_exists($tmpfname . '.1.wav')) {
                    debugUnlink($tmpfname . '.1.wav');
                }
                if (file_exists($tmpfname . '.2.wav')) {
                    debugUnlink($tmpfname . '.2.wav');
                }
                return false;
            } else {
                $cmd = 'sox -q --combine concatenate "' . $tmpfname . '.1.wav" "' . $tmpfname . '.2.wav" -r ' . $GLOBALS['RATE'] . ' -c 2 "' . $output . '"';
                if (debugExec($cmd) != 0) {
                    if (file_exists($output)) {
                        debugUnlink($output);
                    }
                    if (file_exists($tmpfname . '.1.wav')) {
                        debugUnlink($tmpfname . '.1.wav');
                    }
                    if (file_exists($tmpfname . '.2.wav')) {
                        debugUnlink($tmpfname . '.2.wav');
                    }
                    return false;
                } else {
                    if (file_exists($tmpfname . '.1.wav')) {
                        debugUnlink($tmpfname . '.1.wav');
                    }
                    if (file_exists($tmpfname . '.2.wav')) {
                        debugUnlink($tmpfname . '.2.wav');
                    }
                    return true;
                }
            }
        } elseif ($remove_sources == true) {
            debugUnlink($first);
            debugUnlink($second);
        }
        return true;
    } else {
        return false;
    }
}

/**
 * This function invokes SoX to place the first track before the intermediate and last tracks and return that as the output file, optionally, removing the sources once it's done
 *
 * @param path    $first          The first file to use
 * @param path    $last           The last file to use
 * @param path    $intermediate   The intermediate file - with {n} token to substitute in the cycled value of $num_tracks
 * @param int     $num_tracks     The number of intermediate files to cycle through
 * @param path    $output         The output location of the combined two files
 * @param boolean $remove_sources Whether the script should remove the two source files.
 *
 * @return boolean Success or Failure
 */
function concatenateMultiTracks($first, $last, $intermediate, $num_tracks, $output, $remove_sources = true)
{
    if (file_exists($first) and file_exists($last)) {
        $cmd = 'sox -q "' . $first . '" {multi} "' . $last . '"  "' . $output . '"';

	$multi = '';

	for($counter=1;$counter<=$num_tracks;$counter++) {
            $multi .=  '"' . str_replace( '{n}', $counter, $intermediate ) . '" ';
        }

	$cmd = str_replace( '{multi}', $multi, $cmd );

        if (debugExec($cmd) != 0) {
            if (file_exists($output)) {
                debugUnlink($output);
            }
        } elseif ($remove_sources == true) {
            debugUnlink($first);
            debugUnlink($last);
            for($counter=1;$counter<=$num_tracks;$counter++) {
                debugUnlink( str_replace( '{n}', $counter, $intermediate ) );
            }
        }

    }
}

/**
* This function invokes SoX to merge the first and second tracks and return that as the output file, optionally, removing the sources once it's done
*
* @param path    $first          The first file to use
* @param path    $second         The second file to use
* @param path    $output         The output location of the combined two files
* @param boolean $remove_sources Whether the script should remove the two source files.
*
* @return boolean Success or Failure
*/
function overlayAudioTracks($first, $second, $output, $remove_sources = true)
{
    if (file_exists($first) and file_exists($second)) {
        $cmd = 'sox -q -m -r ' . $GLOBALS['RATE'] . ' -c 2 "' . $first . '" -r ' . $GLOBALS['RATE'] . ' -c 2 "' . $second . '" -r ' . $GLOBALS['RATE'] . ' -c 2 "' . $output . '"';
        if (debugExec($cmd) != 0) {
            if (file_exists($output)) {
                debugUnlink($output);
            }
            // It may be that they wouldn't handle the sample rates - try converting to the desired format forst, and then merge.
            $tmpfname = tempnam(dirname(__FILE__) . '/TEMP', "fileconvert_");
            $cmd1 = 'sox -q "' . $first . '" -r ' . $GLOBALS['RATE'] . ' -c 2 "' . $tmpfname . '.1.wav"';
            $cmd2 = 'sox -q "' . $second . '" -r ' . $GLOBALS['RATE'] . ' -c 2 "' . $tmpfname . '.2.wav"';
            if (debugExec($cmd1) != 0 || debugExec($cmd2) != 0) {
                if (file_exists($tmpfname . '.1.wav')) {
                    debugUnlink($tmpfname . '.1.wav');
                }
                if (file_exists($tmpfname . '.2.wav')) {
                    debugUnlink($tmpfname . '.2.wav');
                }
                return false;
            } else {
                $cmd = 'sox -q -m "' . $tmpfname . '.1.wav" "' . $tmpfname . '.2.wav" -r ' . $GLOBALS['RATE'] . ' -c 2 "' . $output . '"';
                if (debugExec($cmd) != 0) {
                    if (file_exists($output)) {
                        debugUnlink($output);
                    }
                    if (file_exists($tmpfname . '.1.wav')) {
                        debugUnlink($tmpfname . '.1.wav');
                    }
                    if (file_exists($tmpfname . '.2.wav')) {
                        debugUnlink($tmpfname . '.2.wav');
                    }
                    return false;
                } else {
                    if (file_exists($tmpfname . '.1.wav')) {
                        debugUnlink($tmpfname . '.1.wav');
                    }
                    if (file_exists($tmpfname . '.2.wav')) {
                        debugUnlink($tmpfname . '.2.wav');
                    }
                    return true;
                }
            }
        } elseif ($remove_sources == true) {
            debugUnlink($first);
            debugUnlink($second);
        }
        return true;
    } else {
        return false;
    }
}

/**
 * Invoke SoX to reverse the content of an audio file, removing the source if requested
 *
 * @param path    $input          The file to reverse
 * @param path    $output         The output location of that reversing action
 * @param boolean $remove_sources Whether the script should remove the two source files.
 *
 * @return boolean Success or Failure
 */
function reverseTrackAudio($input, $output, $remove_sources = true)
{
    if (file_exists($input)) {
        $cmd = 'sox -q "' . $input . '" "' . $output . '" reverse';
        if (debugExec($cmd) != 0) {
            if (file_exists($output)) {
                debugUnlink($output);
            }
            return false;
        } elseif ($remove_sources == true) {
            debugUnlink($input);
        }
        return true;
    } else {
        return false;
    }
}

/**
 * Return the length of the input file
 *
 * @param path $input The file to process
 *
 * @return float Number of seconds the file runs for
 */
function getTrackLength($input)
{
    if (file_exists($input)) {
        $cmd = 'soxi -D "' . $input . '"';
        list($exit, $content) = debugExec($cmd, true);
        if ($exit != 0) {
            return 0;
        }
        return $content;
    } else {
        return 0;
    }
}

/**
 * Ensure a consistent output of files
 *
 * @param path $input  The source file
 * @param path $output The destination file
 *
 * @return boolean Success or Failure
 */
function convertToWavOutput($input, $output)
{
    if (file_exists($input)) {
        $cmd = 'sox -q "' . $input . '" -r ' . $GLOBALS['RATE'] . ' -c 2 "' . $output . '"';
        if (debugExec($cmd) != 0) {
            if (file_exists($output)) {
                debugUnlink($output);
            }
            return false;
        }
        return true;
    } else {
        return false;
    }
}

/**
 * Process a JSON encoded array of data as a string into an array, add a new key and value, return the result, as a JSON encoded array
 *
 * @param json    $original   The original string to be used as the source
 * @param mixed   $key        The key value to use with the new entry in the array (potentially cumulative)
 * @param mixed   $value      The value to use with the new entry.
 * @param boolean $cumulative If this is set to true, increment the key value by the last value to be used.
 *
 * @return json The json encoded string representing the array.
 */
function addEntryToJsonArray($original, $key, $value, $cumulative = false)
{
    $array = makeArrayFromObjects(json_decode($original));
    if ($cumulative == true && count($array) > 0) {
        foreach ($array as $array_key=>$array_value) {
            // A dirty way to get the last key=>value pair
        }
        $key = (float) $array_key + (float) $key;
    }
    $array[(string) $key] = $value;
    return json_encode($array);
}

/**
 * Itterate through a passed array, converting any objects into arrays
 *
 * @param array|object $src_array An array or object containing data we need as an array
 *
 * @return array The resulting array
 */
function makeArrayFromObjects($src_array)
{
    $array = (array) $src_array;
    $new_array = array();
    foreach ($array as $array_key => $array_item) {
        if (is_object($array_item)) {
            $new_array[(string) $array_key] = makeArrayFromObjects($array_item);
        } else {
            $new_array[(string) $array_key] = $array_item;
        }
    }
    return $new_array;
}

/**
 * A wrapper function for the next few functions
 *
 * @param path  $input       The filename we want to process into various output formats
 * @param path  $output_root The filename root (i.e. /path/to/track. rather than /path/to/track.mp3) we want to use for the further functions
 * @param array $arrMetadata The metadata to apply to the new file formats
 *
 * @return void
 */
function generateOutputTracks($input, $output_root, $arrMetadata)
{
    // eyeD3 doesn't support "MP3 Extended" (AKA MP3 with Chapter Support), but it has been requested.
    generateOutputTracksAsMp3($input, $output_root . 'mp3', $arrMetadata);
    // Chapter information thanks to this page: http://code.google.com/p/subler/wiki/ChapterTextFormat
    generateOutputTracksAsOga($input, $output_root . 'oga', $arrMetadata);
    generateOutputTracksAsM4a($input, $output_root, 'm4a', $arrMetadata);
    debugUnlink($input);
}

/**
* Given a source filename, generate an MP3 from that source.
*
* @param path  $input       The filename we want to process
* @param path  $output      The resulting filename
* @param array $arrMetadata The metadata to apply to the new file format
*
* @return boolean Success or failure
*/
function generateOutputTracksAsMp3($input, $output, $arrMetadata)
{
    if (file_exists($input)) {
        $cmd = 'sox "' . $input . '" -t mp3 "' . $output . '"';
        if (debugExec($cmd) != 0) {
            if (file_exists($output)) {
                debugUnlink($output);
            }
            return false;
        }
        $cmd = 'eyeD3';
        if (isset($arrMetadata['Artist']) and $arrMetadata['Artist'] != '') {
            $cmd .= ' --artist="' . $arrMetadata['Artist'] . '"';
        }
        if (isset($arrMetadata['Title']) and $arrMetadata['Title'] != '') {
            $cmd .= ' --title="' . $arrMetadata['Title'] . '"';
        }
        if (isset($arrMetadata['AlbumArt']) and $arrMetadata['AlbumArt'] != '') {
            $cmd .= ' --add-image "' . $arrMetadata['AlbumArt'] . '":FRONT_COVER';
        }
        $cmd .= ' "' . $output . '"';
        if (debugExec($cmd) != 0) {
            if (file_exists($output)) {
                debugUnlink($output);
            }
            return false;
        }
        return true;
    } else {
        return false;
    }
}

/**
 * Given a source filename, generate an OGA file from that source.
 *
 * @param path  $input       The filename we want to process
 * @param path  $output      The resulting filename
 * @param array $arrMetadata The metadata to apply to the new file format
 *
 * @return boolean Success or failure
 */
function generateOutputTracksAsOga($input, $output, $arrMetadata)
{
    if (file_exists($input)) {
        $cmd = 'sox "' . $input . '" -t ogg "' . $output . '"';
        if (debugExec($cmd) != 0) {
            if (file_exists($output)) {
                debugUnlink($output);
            }
            return false;
        }
        $content = '';
        if (isset($arrMetadata['AlbumArt']) and $arrMetadata['AlbumArt'] != false) {
            $in = fopen($arrMetadata['AlbumArt'], "r");
            $imgbinary = fread($in, filesize($arrMetadata['AlbumArt']));
            fclose($in);
            $content .= "METADATA_BLOCK_PICTURE=" . base64_encode($imgbinary) . "\r\n";
            $content .= "COVERART=" . base64_encode($imgbinary) . "\r\n";
        }
        $out = fopen(Configuration::getWorkingDir() . '/oga_comments', 'w');
        fwrite($out, $content);
        fclose($out);
        $cmd = 'vorbiscomment --write "' . $output . '" --raw --commentfile "' . Configuration::getWorkingDir() . '/oga_comments' . '"';
        if (debugExec($cmd) != 0) {
            if (file_exists($output)) {
                debugUnlink($output);
            }
            return false;
        }
        $content = '';
        if (isset($arrMetadata['Title'])) {
            $content .= "TITLE={$arrMetadata['Title']}\r\n";
        }
        if (isset($arrMetadata['Artist'])) {
            $content .= "ARTIST={$arrMetadata['Artist']}\r\n";
        }
        if (isset($arrMetadata['RunningOrder']) && is_array($arrMetadata['RunningOrder']) && count($arrMetadata['RunningOrder']) > 0) {
            $chapter_no = 0;
            foreach ($arrMetadata['RunningOrder'] as $timestamp => $chapter) {
                $chapter_no++;
                $content .= 'CHAPTER';
                $content .= str_pad($chapter_no, 2, '0', STR_PAD_LEFT);
                $content .= '=';
                $content .= str_pad(intval(intval($timestamp) / 3600), 2, '0', STR_PAD_LEFT) . ':';
                $content .= str_pad(bcmod((intval($timestamp) / 60), 60), 2, '0', STR_PAD_LEFT) . ':';
                $content .= str_pad(bcmod(intval($timestamp), 60), 2, '0', STR_PAD_LEFT) . '.';
                $content .= substr(str_pad(substr($timestamp - intval($timestamp), 2), 3, '0', STR_PAD_RIGHT), 0, 3) . "\r\n";
                $content .= 'CHAPTER';
                $content .= str_pad($chapter_no, 2, '0', STR_PAD_LEFT);
                $content .= 'NAME=';
                if (is_array($chapter)) {
                    $content .= $chapter['strTrackName'] . ' by ' . $chapter['strArtistName'] . "\r\n";
                } else {
                    $content .= $chapter . "\r\n";
                }
            }
        }
        $out = fopen(Configuration::getWorkingDir() . '/oga_comments', 'w');
        fwrite($out, $content);
        fclose($out);
        $cmd = 'vorbiscomment --append "' . $output . '" --raw --commentfile "' . Configuration::getWorkingDir() . '/oga_comments' . '"';
        if (debugExec($cmd) != 0) {
            if (file_exists($output)) {
                debugUnlink($output);
            }
            return false;
        }
        debugUnlink(Configuration::getWorkingDir() . '/oga_comments');
        return true;
    } else {
        return false;
    }
}

/**
 * Given a source filename, generate an M4A from that source.
 *
 * @param path   $input       The filename we want to process
 * @param path   $output_root The filename root (i.e. /path/to/track. rather than /path/to/track.m4a) we want to use for the further functions
 * @param string $suffix      The filename type to apply to the end of this file type (probably m4a)
 * @param array  $arrMetadata The metadata to apply to the new file format
 *
 * @return boolean Success or failure
 */
function generateOutputTracksAsM4a($input, $output_root, $suffix, $arrMetadata)
{
    $DEBUG = true;
    $output = $output_root . $suffix;
    if (file_exists($input)) {
        $cmd = 'avconv -y -i "' . $input . '" -ac 2 -ar ' . $GLOBALS['RATE'] . ' -ab 128k -strict experimental "' . $output . '"';
        if (debugExec($cmd) != 0) {
            if (file_exists($output)) {
                debugUnlink($output);
            }
            return false;
        }
        $cmd = 'mp4tags -r AabcCdDeEgGHiIjlLmMnNoOpPBRsStTxXwyzZ "' . $output . '"';
        exec($cmd, $result, $exit_code);
        if (debugExec($cmd) != 0) {
            if (file_exists($output)) {
                debugUnlink($output);
            }
            return false;
        }
        $cmd = 'mp4tags ';
        if (isset($arrMetadata['Artist']) and $arrMetadata['Artist'] != '') {
            $cmd .= ' -a "' . $arrMetadata['Artist'] . '"';
        }
        if (isset($arrMetadata['Title']) and $arrMetadata['Title'] != '') {
            $cmd .= ' -s "' . $arrMetadata['Title'] . '"';
        }
        $cmd .= ' "' . $output . '"';
        if (debugExec($cmd) != 0) {
            if (file_exists($output)) {
                debugUnlink($output);
            }
            return false;
        }
        if (isset($arrMetadata['AlbumArt']) and $arrMetadata['AlbumArt'] != '') {
            $cmd = 'mp4art --add "' . $arrMetadata['AlbumArt'] . '" ' . $output;
            if (debugExec($cmd) != 0) {
                if (file_exists($output)) {
                    debugUnlink($output);
                }
                return false;
            }
        }
        $content = '';
        if (isset($arrMetadata['RunningOrder']) && is_array($arrMetadata['RunningOrder']) && count($arrMetadata['RunningOrder']) > 0) {
            $chapter_no = 0;
            foreach ($arrMetadata['RunningOrder'] as $timestamp => $chapter) {
                $chapter_no++;
                $content .= str_pad(intval(intval($timestamp) / 3600), 2, '0', STR_PAD_LEFT) . ':';
                $content .= str_pad(bcmod((intval($timestamp) / 60), 60), 2, '0', STR_PAD_LEFT) . ':';
                $content .= str_pad(bcmod(intval($timestamp), 60), 2, '0', STR_PAD_LEFT) . '.';
                $content .= substr(str_pad(substr($timestamp - intval($timestamp), 2), 3, '0', STR_PAD_RIGHT), 0, 3) . " ";
                if (is_array($chapter)) {
                    $content .= $chapter['strTrackName'] . ' by ' . $chapter['strArtistName'] . "\r\n";
                } else {
                    $content .= $chapter . "\r\n";
                }
            }
        }
        $out = fopen($output_root . 'chapters.txt', 'w');
        fwrite($out, $content);
        fclose($out);
        $cmd = 'mp4chaps --import "' . $output . '"';
        if (debugExec($cmd) != 0) {
            if (file_exists($output_root . 'chapters.txt')) {
                debugUnlink($output_root . 'chapters.txt');
            }
            if (file_exists($output)) {
                debugUnlink($output);
            }
            return false;
        }
        if (file_exists($output_root . 'chapters.txt')) {
            debugUnlink($output_root . 'chapters.txt');
        }
        return true;
    } else {
        return false;
    }
}

/**
 * Delete a file, provided debugging isn't enabled.
 *
 * @param path $file The file to delete
 *
 * @return void
 */
function debugUnlink($file, $pointer = '')
{
    if ($pointer != '' && isset($GLOBALS['DEBUG']) && $GLOBALS['DEBUG']) {
        debugout::dump("Unlinking file $file at $pointer");
    }
    if (!isset($GLOBALS['NODELETEFILES']) or ! $GLOBALS['NODELETEFILES']) {
        unlink($file);
    } else {
        debugout::dump("Would be deleting $file now");
    }
}

/**
 * Run a command, and return errors if they are generated. If debugging is enabled, return all generated content anyway.
 *
 * @param string  $cmd                  The command to run
 * @param boolean $return_string_anyway If this is set to true, return both the string and the exit status, instead of just the exit status
 * @param integer $max_acceptable_exit  What is considered a successful exit code from this command
 * @param string  $mask                 Any content to hide
 *
 * @return integer|array Either the exit code and the string of data, or just the exit code
 */
function debugExec($cmd, $return_string_anyway = false, $max_acceptable_exit = 0, $mask = "")
{
    exec($cmd .' 2>&1', $result, $exit_code);
    $content = '';
    if (count($result) > 0) {
        foreach ($result as $line) {
            if ($content != '') {
                $content .= "\r\n";
            }
            $content .= $line;
        }
    }
    if ($exit_code > $max_acceptable_exit || (isset($GLOBALS['DEBUG']) && $GLOBALS['DEBUG'])) {
        $debugcmd = $cmd;
        $debugcontent = $content;
        if ($mask != '') {
          $debugcmd = str_replace($mask, '[REDACTED]', $cmd);
          $debugcontent = str_replace($mask, '[REDACTED]', $content);
        }
        debugout::add("Command:     $debugcmd");
        debugout::add("Exit status: $exit_code");
        debugout::dump("Output:      $debugcontent");
    }
    if ($return_string_anyway == true) {
        return array($exit_code, $content);
    } else {
        return $exit_code;
    }
}

/**
 * A wrapper to the curlGetResource function for files
 *
 * @param string  $url        The URL to retrieve
 * @param integer $loop_count The number of times we've tried this function
 * @param integer $loop_max   The number of times we will try this function
 *
 * @return string|boolean Either the path to the file, or false if the download failed.
 */
function downloadFile($url, $loop_count = 1, $loop_max = 5)
{
    $get = curlGetResource($url);
    if ($get[1]['http_code'] == 200) {
        return $get[0];
    } elseif ($get[1]['http_code'] == 500 && $loop_count < $loop_max) {
        sleep(120);
        return downloadFile($url, $loop_count + 1);
    } else {
        debugout::dump("Downloading file: $url\r\nDownload failed. Error code: " . $get[1]['http_code']);
        debugUnlink($get[0]);
        return false;
    }
}

/**
 * Get url content and response headers (given a url, follows all redirections on it and returned content and response headers of final url)
 *
 * @param string  $url             The URL to retrieve
 * @param integer $as_file         Retrieve this as a file, or just a string of data
 * @param integer $javascript_loop If you have been redirected as part of a JavaScript redirection, follow it.
 * @param integer $timeout         The timeout until we stop trying to retrieve this file, this pass.
 * @param integer $max_loop        The maximum number of redirections to follow
 *
 * @return array Content or filename, then the response values from curl
 */
function curlGetResource($url, $as_file = 1, $javascript_loop = 0, $timeout = 10000, $max_loop = 10)
{
    $url = str_replace("&amp;", "&", urldecode(trim($url)));
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_ENCODING, "");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_AUTOREFERER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);

    if ($as_file == 1) {
        $tmpfname = tempnam(dirname(__FILE__) . '/TEMP', "fileget_");
        $out = fopen($tmpfname, 'wb');
        if ($out == FALSE) {
            die("Unable to write to $tmpfname\r\n");
        }
        curl_setopt($ch, CURLOPT_FILE, $out);
    }

    $content = curl_exec($ch);
    $response = curl_getinfo($ch);
    if (curl_errno($ch) == 56) {
        debugout::add("Connection reset trying to get $url. Sleeping 120 seconds, and then treating this as a redirect.");
        sleep(120);
        return curlGetResource($url, $as_file, $javascript_loop+1, $max_loop);
    }
    if (curl_errno($ch)) {
        $error_text = curl_error($ch);
        debugout::add("Unable to retrieve $url due to $error_text");
        $error = 1;
    }
    curl_close($ch);
    if ($as_file == 1) {
        fclose($out);
    }

    if (isset($error)) {
        return false;
    }

    if ($response['http_code'] == 301 or $response['http_code'] == 302) {
        $headers = get_headers($response['url']);
        if ($headers != false) {
            foreach ($headers as $value) {
                if (substr(strtolower($value), 0, 9) == "location:") {
                    return curlGetResource(trim(substr($value, 9, strlen($value))), $as_file);
                }
            }
        }
    }

    if ($as_file == 0 and (preg_match("/>[[:space:]]+window\.location\.replace\('(.*)'\)/i", $content, $value) or preg_match("/>[[:space:]]+window\.location\=\"(.*)\"/i", $content, $value)) and $javascript_loop < $max_loop) {
        return curlGetResource($value[1], 0, $javascript_loop+1, $max_loop);
    } else {
        if ($as_file == 1) {
            if (isset($GLOBALS['DEBUG']) && $GLOBALS['DEBUG']) {
                debugout::dump("Got $url as file $tmpfname with response as follows:\r\n" . print_r($response, true));
            }
            return array($tmpfname, $response);
        } else {
            if (isset($GLOBALS['DEBUG']) && $GLOBALS['DEBUG']) {
                debugout::dump("Got $url as\r\n" . substr($content, 0, 60) . "\r\ncURL data as follows:\r\n" . print_r($response, true));
            }
            return array($content, $response);
        }
    }
}

/**
 * POST to a web server.
 *
 * @param string $url     The URL to call
 * @param array  $arrPost The variables to pass to the Web Server
 *
 * @return array Response from the web server
 */
function curlPostRequest($url, $arrPost)
{
    $timeout = 10000;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,$url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_ENCODING, "");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_AUTOREFERER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_POST,1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $arrPost);
    $result = curl_exec($ch);
    $response = curl_getinfo($ch);
    if (curl_errno($ch)) {
        $error_text = curl_error($ch);
        debugout::dump("Unable to retrieve $url due to $error_text");
        $error = 1;
    }
    curl_close($ch);
    if ($response['http_code'] != 200 || isset($error)) {
        $state = false;
    } else {
        $state = true;
    }
    if (isset($GLOBALS['DEBUG']) && $GLOBALS['DEBUG']) {
        $note = "";
        if ($state == false) {
            $note = "Not ";
        }
        debugout::dump($note . "Got $url with data:\r\n" . print_r($arrPost, true) . "\r\nas\r\n$result\r\ncURL data as follows:\r\n" . print_r($response, true));
    }
    return array($state, $result, $response);
}

/**
* Returns the Path and query values for this script
*
* @return array[0] URI
* @return array[1] Query values
*/
function getPath()
{
    if ( ! isset($_SERVER['REQUEST_METHOD'])) {
        if (preg_match('/\/(.*)$/', $GLOBALS['argv'][0]) == 0) {
            $filename = trim(`pwd`) . '/' . $GLOBALS['argv'][0];
        } else {
            $filename = $GLOBALS['argv'][0];
        }
        $uri = 'file://' . $filename;
        if (isset($data[0])) {
            unset($data[0]);
        }
        $data = $GLOBALS['argv'];
    } else {
        $uri = "http";
        if (isset($_SERVER['HTTPS'])) {
            $uri .= 's';
        }
        $uri .= '://' . $_SERVER['SERVER_NAME'];
        if ((isset($_SERVER['HTTPS']) and $_SERVER['SERVER_PORT'] != 443) or ( ! isset($_SERVER['HTTPS']) and $_SERVER['SERVER_PORT'] != 80)) {
            $uri .= ':' . $_SERVER['SERVER_PORT'];
        }
        $uri .= $_SERVER['REQUEST_URI'];
        switch(strtolower($_SERVER['REQUEST_METHOD'])) {
        case 'get':
            $data = $_GET;
            break;
        case 'post':
            $data = $_POST;
            if (isset($_FILES) and is_array($_FILES)) {
                $data['_FILES'] = $_FILES;
            }
            break;
        case 'put':
            parse_str(file_get_contents('php://input'), $_PUT);
            $data = $_PUT;
            break;
        case 'delete':
        case 'head':
            $data = $_REQUEST;
        }
    }
    return array($uri, $data);
}

/**
 * Returns the URI for this script
 *
 * @return array URI
 */
function getUri()
{
    list($uri, $data) = getPath();
    $arrUrl = parse_url($uri);
    $arrUrl['full'] = $uri;
    $match = preg_match('/^([^\?]+)/', $arrUrl['full'], $matches);
    if ($match > 0) {
        $arrUrl['no_params'] = $matches[1];
    } else {
        $arrUrl['no_params'] = $arrUrl['full'];
    }
    $arrUrl['parameters'] = $data;
    if (substr($arrUrl['path'], -1) == '/') {
        $arrUrl['path'] = substr($arrUrl['path'], 0, -1);
    }
    $match = preg_match('/\/(.*)/', $arrUrl['path'], $matches);
    if ($match > 0) {
        $arrUrl['path'] = $matches[1];
    }
    $arrUrl['site_path'] = '';
    $arrUrl['router_path'] = $arrUrl['path'];
    if (isset($_SERVER['SCRIPT_NAME']) and isset($_SERVER['REQUEST_METHOD'])) {
        $path_elements = str_split($arrUrl['path']);
        $match = preg_match('%/(.*)$%', $_SERVER['SCRIPT_NAME'], $matches);
        $script_elements = str_split($matches[1]);
        $char = 0;
        while (isset($path_elements[$char]) and $path_elements[$char] == $script_elements[$char]) {
            $char++;
        }
        $arrUrl['site_path'] = substr($arrUrl['path'], 0, $char);
        $arrUrl['router_path'] = substr($arrUrl['path'], $char);
    }
    $arrUrl['path_items'] = explode('/', $arrUrl['router_path']);
    $arrLastUrlItem = explode('.', $arrUrl['path_items'][count($arrUrl['path_items'])-1]);
    if (count($arrLastUrlItem) > 1) {
        $arrUrl['path_items'][count($arrUrl['path_items'])-1] = '';
        foreach ($arrLastUrlItem as $key=>$UrlItem) {
            if ($key + 1 == count($arrLastUrlItem)) {
                $arrUrl['format'] = $UrlItem;
            } else {
                if ($arrUrl['path_items'][count($arrUrl['path_items'])-1] != '') {
                    $arrUrl['path_items'][count($arrUrl['path_items'])-1] .= '.';
                }
                $arrUrl['path_items'][count($arrUrl['path_items'])-1] .= $UrlItem;
            }
        }
    } else {
        $arrUrl['format'] = '';
    }
    $arrUrl['basePath'] = "{$arrUrl['scheme']}://";
    if (isset($arrUrl['host'])) {
        $arrUrl['basePath'] .= $arrUrl['host'];
    }
    if (isset($arrUrl['port']) and $arrUrl['port'] != '') {
        $arrUrl['basePath'] .= ':' . $arrUrl['port'];
    }
    if (isset($arrUrl['site_path']) and $arrUrl['site_path'] != '') {
        $arrUrl['basePath'] .= '/' . $arrUrl['site_path'];
    }
    $arrUrl['basePath'] .=  '/';
    if (isset($_SERVER['HTTP_USER_AGENT'])) {
        // Remember, this isn't guaranteed to be accurate
        $arrUrl['ua'] = $_SERVER['HTTP_USER_AGENT'];
    }
    return $arrUrl;
}

/**
 * Execute the SCP command to transfer the file to the server
 *
 * @param $source     string  The source file to upload
 * @param $showtype   string  The type of file to upload
 * @param $showdate   integer The date of the file to upload (in the format yyyymm or yyyymmdd)
 * @param $showformat string  The file format we're uploading (e.g. mp3, oga, m4a)
 *
 * @return void
 */
function runSFTP($source, $showtype, $showdate, $showformat)
{
    $cmd = '';
    if (Configuration::getSCPpassword() != "") {
        $cmd = 'sshpass -p "' . Configuration::getSCPpassword() . '" ';;
    }
    $cmd .= 'scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null ' . $source . $showformat . ' ';
    if (Configuration::getSCPuser() != "") {
        $cmd .= Configuration::getSCPuser() . '@';
    }
    $cmd .= Configuration::getSCPtarget() . ':';
    if (Configuration::getSCPpath() != "") {
        $cmd .= Configuration::getSCPpath() . '/';
    }
    $cmd .= $showtype .'/' . $showdate . '.' . $showformat;
    debugExec($cmd, false, 0, Configuration::getSCPpassword());
}

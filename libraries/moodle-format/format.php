<?php

define('FORMAT_MOODLE',   '0');
define('FORMAT_HTML',     '1');
define('FORMAT_PLAIN',    '2');
define('FORMAT_WIKI',     '3');
define('FORMAT_MARKDOWN', '4');

function fix_utf8($value) {
    if (is_null($value) or $value === '') {
        return $value;

    } else if (is_string($value)) {
        if ((string)(int)$value === $value) {
            // Shortcut.
            return $value;
        }
        // No null bytes expected in our data, so let's remove it.
        $value = str_replace("\0", '', $value);

        // Note: this duplicates min_fix_utf8() intentionally.
        static $buggyiconv = null;
        if ($buggyiconv === null) {
            $buggyiconv = (!function_exists('iconv') or @iconv('UTF-8', 'UTF-8//IGNORE', '100'.chr(130).'€') !== '100€');
        }

        if ($buggyiconv) {
            if (function_exists('mb_convert_encoding')) {
                $subst = mb_substitute_character();
                mb_substitute_character('');
                $result = mb_convert_encoding($value, 'utf-8', 'utf-8');
                mb_substitute_character($subst);

            } else {
                // Warn admins on admin/index.php page.
                $result = $value;
            }

        } else {
            $result = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        }

        return $result;

    } else if (is_array($value)) {
        foreach ($value as $k => $v) {
            $value[$k] = fix_utf8($v);
        }
        return $value;

    } else if (is_object($value)) {
        // Do not modify original.
        $value = clone($value);
        foreach ($value as $k => $v) {
            $value->$k = fix_utf8($v);
        }
        return $value;

    } else {
        // This is some other type, no utf-8 here.
        return $value;
    }
}

function get_string($key, $identifier) {
    $strings = require_once(realpath(dirname(__FILE__) . '/lang.php'));
    return isset($strings[$key]) ? $strings[$key] : null;
}

function clean_param($param) {
    if (is_object($param)) {
        $param = $param->__toString();
    }

    // Leave only tags needed for multilang.
    $param = fix_utf8($param);
    // If the multilang syntax is not correct we strip all tags because it would break xhtml strict which is required
    // for accessibility standards please note this cleaning does not strip unbalanced '>' for BC compatibility reasons.
    do {
        if (strpos($param, '</lang>') !== false) {
            // Old and future mutilang syntax.
            $param = strip_tags($param, '<lang>');
            if (!preg_match_all('/<.*>/suU', $param, $matches)) {
                break;
            }
            $open = false;
            foreach ($matches[0] as $match) {
                if ($match === '</lang>') {
                    if ($open) {
                        $open = false;
                        continue;
                    } else {
                        break 2;
                    }
                }
                if (!preg_match('/^<lang lang="[a-zA-Z0-9_-]+"\s*>$/u', $match)) {
                    break 2;
                } else {
                    $open = true;
                }
            }
            if ($open) {
                break;
            }
            return $param;

        } else if (strpos($param, '</span>') !== false) {
            // Current problematic multilang syntax.
            $param = strip_tags($param, '<span>');
            if (!preg_match_all('/<.*>/suU', $param, $matches)) {
                break;
            }
            $open = false;
            foreach ($matches[0] as $match) {
                if ($match === '</span>') {
                    if ($open) {
                        $open = false;
                        continue;
                    } else {
                        break 2;
                    }
                }
                if (!preg_match('/^<span(\s+lang="[a-zA-Z0-9_-]+"|\s+class="multilang"){2}\s*>$/u', $match)) {
                    break 2;
                } else {
                    $open = true;
                }
            }
            if ($open) {
                break;
            }
            return $param;
        }
    } while (false);
    // Easy, just strip all tags, if we ever want to fix orphaned '&' we have to do that in format_string().
    return strip_tags($param);
}

function shorten_text($text, $ideal=30, $exact = false, $ending='...') {
    // If the plain text is shorter than the maximum length, return the whole text.
    if (mb_strlen(preg_replace('/<.*?>/', '', $text)) <= $ideal) {
        return $text;
    }

    // Splits on HTML tags. Each open/close/empty tag will be the first thing
    // and only tag in its 'line'.
    preg_match_all('/(<.+?>)?([^<>]*)/s', $text, $lines, PREG_SET_ORDER);

    $totallength = mb_strlen($ending);
    $truncate = '';

    // This array stores information about open and close tags and their position
    // in the truncated string. Each item in the array is an object with fields
    // ->open (true if open), ->tag (tag name in lower case), and ->pos
    // (byte position in truncated text).
    $tagdetails = array();

    foreach ($lines as $linematchings) {
        // If there is any html-tag in this line, handle it and add it (uncounted) to the output.
        if (!empty($linematchings[1])) {
            // If it's an "empty element" with or without xhtml-conform closing slash (f.e. <br/>).
            if (!preg_match('/^<(\s*.+?\/\s*|\s*(img|br|input|hr|area|base|basefont|col|frame|isindex|link|meta|param)(\s.+?)?)>$/is', $linematchings[1])) {
                if (preg_match('/^<\s*\/([^\s]+?)\s*>$/s', $linematchings[1], $tagmatchings)) {
                    // Record closing tag.
                    $tagdetails[] = (object) array(
                            'open' => false,
                            'tag'  => mb_strtolower($tagmatchings[1]),
                            'pos'  => mb_strlen($truncate),
                        );

                } else if (preg_match('/^<\s*([^\s>!]+).*?>$/s', $linematchings[1], $tagmatchings)) {
                    // Record opening tag.
                    $tagdetails[] = (object) array(
                            'open' => true,
                            'tag'  => mb_strtolower($tagmatchings[1]),
                            'pos'  => mb_strlen($truncate),
                        );
                } else if (preg_match('/^<!--\[if\s.*?\]>$/s', $linematchings[1], $tagmatchings)) {
                    $tagdetails[] = (object) array(
                            'open' => true,
                            'tag'  => mb_strtolower('if'),
                            'pos'  => mb_strlen($truncate),
                    );
                } else if (preg_match('/^<!--<!\[endif\]-->$/s', $linematchings[1], $tagmatchings)) {
                    $tagdetails[] = (object) array(
                            'open' => false,
                            'tag'  => mb_strtolower('if'),
                            'pos'  => mb_strlen($truncate),
                    );
                }
            }
            // Add html-tag to $truncate'd text.
            $truncate .= $linematchings[1];
        }

        // Calculate the length of the plain text part of the line; handle entities as one character.
        $contentlength = mb_strlen(preg_replace('/&[0-9a-z]{2,8};|&#[0-9]{1,7};|&#x[0-9a-f]{1,6};/i', ' ', $linematchings[2]));
        if ($totallength + $contentlength > $ideal) {
            // The number of characters which are left.
            $left = $ideal - $totallength;
            $entitieslength = 0;
            // Search for html entities.
            if (preg_match_all('/&[0-9a-z]{2,8};|&#[0-9]{1,7};|&#x[0-9a-f]{1,6};/i', $linematchings[2], $entities, PREG_OFFSET_CAPTURE)) {
                // Calculate the real length of all entities in the legal range.
                foreach ($entities[0] as $entity) {
                    if ($entity[1]+1-$entitieslength <= $left) {
                        $left--;
                        $entitieslength += mb_strlen($entity[0]);
                    } else {
                        // No more characters left.
                        break;
                    }
                }
            }
            $breakpos = $left + $entitieslength;

            // If the words shouldn't be cut in the middle...
            if (!$exact) {
                // Search the last occurence of a space.
                for (; $breakpos > 0; $breakpos--) {
                    if ($char = mb_substr($linematchings[2], $breakpos, 1)) {
                        if ($char === '.' or $char === ' ') {
                            $breakpos += 1;
                            break;
                        } else if (strlen($char) > 2) {
                            // Chinese/Japanese/Korean text can be truncated at any UTF-8 character boundary.
                            $breakpos += 1;
                            break;
                        }
                    }
                }
            }
            if ($breakpos == 0) {
                // This deals with the test_shorten_text_no_spaces case.
                $breakpos = $left + $entitieslength;
            } else if ($breakpos > $left + $entitieslength) {
                // This deals with the previous for loop breaking on the first char.
                $breakpos = $left + $entitieslength;
            }

            $truncate .= mb_substr($linematchings[2], 0, $breakpos);
            // Maximum length is reached, so get off the loop.
            break;
        } else {
            $truncate .= $linematchings[2];
            $totallength += $contentlength;
        }

        // If the maximum length is reached, get off the loop.
        if ($totallength >= $ideal) {
            break;
        }
    }

    // Add the defined ending to the text.
    $truncate .= $ending;

    // Now calculate the list of open html tags based on the truncate position.
    $opentags = array();
    foreach ($tagdetails as $taginfo) {
        if ($taginfo->open) {
            // Add tag to the beginning of $opentags list.
            array_unshift($opentags, $taginfo->tag);
        } else {
            // Can have multiple exact same open tags, close the last one.
            $pos = array_search($taginfo->tag, array_reverse($opentags, true));
            if ($pos !== false) {
                unset($opentags[$pos]);
            }
        }
    }

    // Close all unclosed html-tags.
    foreach ($opentags as $tag) {
        if ($tag === 'if') {
            $truncate .= '<!--<![endif]-->';
        } else {
            $truncate .= '</' . $tag . '>';
        }
    }

    return $truncate;
}

function trim_utf8_bom($str) {
    $bom = "\xef\xbb\xbf";
    if (strpos($str, $bom) === 0) {
        return substr($str, strlen($bom));
    }
    return $str;
}

class qformat_default {

    public $displayerrors = true;
    public $category = null;
    public $questions = array();
    public $course = null;
    public $filename = '';
    public $realfilename = '';
    public $matchgrades = 'error';
    public $catfromfile = 0;
    public $contextfromfile = 0;
    public $cattofile = 0;
    public $contexttofile = 0;
    public $questionids = array();
    public $importerrors = 0;
    public $importerrorsdata = array();
    public $stoponerror = true;
    public $translator = null;
    public $canaccessbackupdata = true;
    protected $importcontext = null;

    public function setFilename($filename) {
        $this->filename = $filename;
    }

    public function setRealfilename($realfilename) {
        $this->realfilename = $realfilename;
    }

    protected function error($message, $text='', $questionname='') {
        $this->importerrorsdata[] = array(
            'message' => $message,
            'text' => $text,
            'questionname' => $questionname,
        );
        $this->importerrors++;
    }

    protected function defaultquestion() {
        $question = new stdClass();
        $question->shuffleanswers = false;
        $question->defaultmark = 1;
        $question->image = "";
        $question->usecase = 0;
        $question->multiplier = array();
        $question->questiontextformat = FORMAT_MOODLE;
        $question->generalfeedback = '';
        $question->generalfeedbackformat = FORMAT_MOODLE;
        $question->answernumbering = 'abc';
        $question->penalty = 0.3333333;
        $question->length = 1;

        // this option in case the questiontypes class wants
        // to know where the data came from
        $question->export_process = true;
        $question->import_process = true;

        $this->add_blank_combined_feedback($question);

        return $question;
    }

    protected function add_blank_combined_feedback($question) {
        $question->correctfeedback = [
            'text' => '',
            'format' => $question->questiontextformat,
            'files' => []
        ];
        $question->partiallycorrectfeedback = [
            'text' => '',
            'format' => $question->questiontextformat,
            'files' => []
        ];
        $question->incorrectfeedback = [
            'text' => '',
            'format' => $question->questiontextformat,
            'files' => []
        ];
        return $question;
    }

    public function clean_question_name($name) {
        $name = clean_param($name); // Matches what the question editing form does.
        $name = trim($name);
        $trimlength = 251;
        while (mb_strlen($name) > 255 && $trimlength > 0) {
            $name = shorten_text($name, $trimlength);
            $trimlength -= 10;
        }
        return $name;
    }

    public function create_default_question_name($questiontext, $default) {
        $name = $this->clean_question_name(shorten_text($questiontext, 80));
        if ($name) {
            return $name;
        } else {
            return $default;
        }
    }

    public function try_importing_using_qtypes($data, $question = null, $extra = null, $qtypehint = '') {
        return false;
    }

    protected function try_exporting_using_qtypes($name, $question, $extra=null) {
        return false;
    }

    protected function readdata($filename) {
        if (is_readable($filename)) {
            $filearray = file($filename);

            if (!isset($filearray[0])) {
                return $filearray;
            }

            // If the first line of the file starts with a UTF-8 BOM, remove it.
            $filearray[0] = trim_utf8_bom($filearray[0]);

            // Check for Macintosh OS line returns (ie file on one line), and fix.
            if (preg_match("~\r~", $filearray[0]) AND !preg_match("~\n~", $filearray[0])) {
                return explode("\r", $filearray[0]);
            } else {
                return $filearray;
            }
        }
        return false;
    }

    protected function readquestions($lines) {

        $questions = array();
        $currentquestion = array();

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                if (!empty($currentquestion)) {
                    if ($question = $this->readquestion($currentquestion)) {
                        $questions[] = $question;
                    }
                    $currentquestion = array();
                }
            } else {
                $currentquestion[] = $line;
            }
        }

        if (!empty($currentquestion)) {  // There may be a final question
            if ($question = $this->readquestion($currentquestion)) {
                $questions[] = $question;
            }
        }

        return $questions;
    }

    public function importprocess($category = null) {
        // parse the file
        if (! $lines = $this->readdata($this->filename)) {
            return false;
        }

        if (!$questions = $this->readquestions($lines)) {   // Extract all the questions
            return false;
        }

        // check for errors before we continue
        if ($this->stoponerror and ($this->importerrors>0)) {
            return false;
        }

        return $questions;
    }
}

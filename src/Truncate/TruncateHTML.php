<?php
/**
 * @file
 * Contains trim functionality as noted on
 *    http://www.pjgalbraith.com/2011/11/truncating-text-html-with-php/
 * with some modifications to adhear to the Drupal Coding Standards.
 */

/*
 Copyright 2011  Patrick Galbraith  (email : patrick.j.galbraith@gmail.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

namespace Drupal\smart_trim\Truncate;

/**
 * Class TruncateHTML
 */
class TruncateHTML {

  /**
   * @type int
   */
  public $charCount = 0;
  /**
   * @type int
   */
  public $wordCount = 0;
  /**
   * @type int
   */
  public $limit;
  /**
   * @type
   */
  public $startNode;
  /**
   * @type string
   */
  public $ellipsis;
  /**
   * @type bool
   */
  public $foundBreakpoint = FALSE;


  /**
   * Sets up object for use.
   *
   * @param string $html
   *   Text to be prepared.
   * @param int $limit
   *   Amount of text to return.
   * @param string $ellipsis
   *   Characters to use at the end of the text.
   *
   * @return \DOMDocument
   *   Prepared DOMDocument to work with.
   */
  private function init($html, $limit, $ellipsis) {

    $dom = new \DOMDocument();
    $dom->loadHTML($html);

    // The body tag node, our html fragment is automatically wrapped in
    // a <html><body> etc... skeleton which we will strip later.
    $this->startNode = $dom->getElementsByTagName("body")->item(0);
    $this->limit = $limit;
    $this->ellipsis = $ellipsis;
    $this->charCount = 0;
    $this->wordCount = 0;
    $this->foundBreakpoint = FALSE;

    return $dom;
  }

  /**
   * Truncates HTML text by characters.
   *
   * @param string $html
   *   Text to be updated.
   * @param int $limit
   *   Amount of text to allow.
   * @param string $ellipsis
   *   Characters to use at the end of the text.
   *
   * @return mixed
   *   Resulting text.
   */
  public function truncateChars($html, $limit, $ellipsis = '...') {

    if ($limit <= 0 || $limit >= strlen(strip_tags($html))) {
      return $html;
    }

    $dom = $this->init($html, $limit, $ellipsis);

    // Pass the body node on to be processed.
    $this->domNodeTruncateChars($this->startNode);

    // Hack to remove the html skeleton that is added,
    // unfortunately this can't be avoided unless php > 5.3.
    return preg_replace('~<(?:!DOCTYPE|/?(?:html|head|body))[^>]*>\s*~i', '', $dom->saveHTML());
  }

  /**
   * Truncates HTML text by words.
   *
   * @param string $html
   *   Text to be updated.
   * @param int $limit
   *   Amount of text to allow.
   * @param string $ellipsis
   *   Characters to use at the end of the text.
   *
   * @return mixed
   *   Resulting text.
   */
  public function truncateWords($html, $limit, $ellipsis = '...') {

    if ($limit <= 0 || $limit >= $this->countWords(strip_tags($html))) {
      return $html;
    }

    $dom = $this->init($html, $limit, $ellipsis);
    // Pass the body node on to be processed.
    $this->domNodeTruncateWords($this->startNode);
    // Hack to remove the html skeleton that is added,
    // unfortunately this can't be avoided unless php > 5.3.
    return preg_replace('~<(?:!DOCTYPE|/?(?:html|head|body))[^>]*>\s*~i', '', $dom->saveHTML());
  }

  /**
   * Truncates a DOMNode by character count.
   *
   * @param \DOMNode $domnode
   *   Object to be truncated.
   */
  private function domNodeTruncateChars(\DOMNode $domnode) {

    foreach ($domnode->childNodes as $node) {

      if ($this->foundBreakpoint == TRUE) {
        return;
      }

      if ($node->hasChildNodes()) {
        $this->domNodeTruncateChars($node);
      }
      else {
        if (($this->charCount + strlen($node->nodeValue)) >= $this->limit) {
          // We have found our end point.
          $node->nodeValue = substr($node->nodeValue, 0, $this->limit - $this->charCount);
          $this->removeProceedingNodes($node);
          $this->insertEllipsis($node);
          $this->foundBreakpoint = TRUE;
          return;
        }
        else {
          $this->charCount += strlen($node->nodeValue);
        }
      }
    }
  }

  /**
   * Truncates a DOMNode by words.
   *
   * @param \DOMNode $domnode
   *   Object to be truncated.
   */
  private function domNodeTruncateWords(\DOMNode $domnode) {

    foreach ($domnode->childNodes as $node) {

      if ($this->foundBreakpoint == TRUE) {
        return;
      }

      if ($node->hasChildNodes()) {
        $this->domNodeTruncateWords($node);
      }
      else {
        $cur_count = $this->countWords($node->nodeValue);

        if (($this->wordCount + $cur_count) >= $this->limit) {
          // We have found our end point.
          if ($cur_count > 1 && ($this->limit - $this->wordCount) < $cur_count) {
            $words = preg_split("/[\n\r\t ]+/", $node->nodeValue, ($this->limit - $this->wordCount) + 1, PREG_SPLIT_NO_EMPTY|PREG_SPLIT_OFFSET_CAPTURE);
            end($words);
            $last_word = prev($words);
            $node->nodeValue = substr($node->nodeValue, 0, $last_word[1] + strlen($last_word[0]));
          }

          $this->removeProceedingNodes($node);
          $this->insertEllipsis($node);
          $this->foundBreakpoint = TRUE;
          return;
        }
        else {
          $this->wordCount += $cur_count;
        }
      }
    }
  }

  /**
   * Removes preceding sibling node.
   *
   * @param \DOMNode $domnode
   *   Node to be altered.
   */
  private function removeProceedingNodes(\DOMNode $domnode) {
    $nextnode = $domnode->nextSibling;

    if ($nextnode !== NULL) {
      $this->removeProceedingNodes($nextnode);
      $domnode->parentNode->removeChild($nextnode);
    }
    else {
      // Scan upwards till we find a sibling.
      $curnode = $domnode->parentNode;
      while ($curnode !== $this->startNode) {
        if ($curnode->nextSibling !== NULL) {
          $curnode = $curnode->nextSibling;
          $this->removeProceedingNodes($curnode);
          $curnode->parentNode->removeChild($curnode);
          break;
        }
        $curnode = $curnode->parentNode;
      }
    }
  }

  /**
   * Inserts the Elipsis character to the node.
   *
   * @param \DOMNode $domnode
   *   Node to be altered.
   */
  private function insertEllipsis(\DOMNode $domnode) {
    // HTML tags to avoid appending the ellipsis to.
    $avoid = array('a', 'strong', 'em', 'h1', 'h2', 'h3', 'h4', 'h5');

    if (in_array($domnode->parentNode->nodeName, $avoid) && ($domnode->parentNode->parentNode !== NULL || $domnode->parentNode->parentNode !== $this->startNode)) {
      // Append as text node to parent instead.
      $textnode = new \DOMText($this->ellipsis);

      if ($domnode->parentNode->parentNode->nextSibling) {
        $domnode->parentNode->parentNode->insertBefore($textnode, $domnode->parentNode->parentNode->nextSibling);
      }
      else {
        $domnode->parentNode->parentNode->appendChild($textnode);
      }
    }
    else {
      // Append to current node.
      $domnode->nodeValue = rtrim($domnode->nodeValue) . $this->ellipsis;
    }
  }

  /**
   * Gets number of words in text.
   *
   * @param string $text
   *   Text to be counted.
   *
   * @return int
   *   Results
   */
  private function countWords($text) {
    $words = preg_split("/[\n\r\t ]+/", $text, -1, PREG_SPLIT_NO_EMPTY);
    return count($words);
  }
}

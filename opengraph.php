<?php
/*
  Copyright 2010 Scott MacVicar

   Licensed under the Apache License, Version 2.0 (the "License");
   you may not use this file except in compliance with the License.
   You may obtain a copy of the License at

       http://www.apache.org/licenses/LICENSE-2.0

   Unless required by applicable law or agreed to in writing, software
   distributed under the License is distributed on an "AS IS" BASIS,
   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
   See the License for the specific language governing permissions and
   limitations under the License.
   
	Original can be found at https://github.com/scottmac/opengraph/blob/master/OpenGraph.php
   
*/

class OpenGraph implements Iterator
{
  /**
   * There are base schema's based on type, this is just
   * a map so that the schema can be obtained
   *
   */
	public static $TYPES = array(
		'activity' => array('activity', 'sport'),
		'business' => array('bar', 'company', 'cafe', 'hotel', 'restaurant'),
		'group' => array('cause', 'sports_league', 'sports_team'),
		'organization' => array('band', 'government', 'non_profit', 'school', 'university'),
		'person' => array('actor', 'athlete', 'author', 'director', 'musician', 'politician', 'public_figure'),
		'place' => array('city', 'country', 'landmark', 'state_province'),
		'product' => array('album', 'book', 'drink', 'food', 'game', 'movie', 'product', 'song', 'tv_show'),
		'website' => array('blog', 'website'),
	);

  /**
   * Holds all the Open Graph values we've parsed from a page
   *
   */
       private $_values = array();

       /**
        * Default User Agent for HTTP requests
        */
       private static $USER_AGENT = 'Mozilla/5.0 (compatible; OpenGraphPHP/1.0)';

  /**
   * Fetches a URI and parses it for Open Graph data, returns
   * false on error.
   *
   * @param $URI    URI to page to parse for Open Graph data
   * @return OpenGraph
   */
       static public function fetch($URI) {
               $ch = curl_init($URI);
               curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
               curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
               curl_setopt($ch, CURLOPT_USERAGENT, self::$USER_AGENT);
               curl_setopt($ch, CURLOPT_TIMEOUT, 10);

               $response = curl_exec($ch);
               curl_close($ch);

               if (!empty($response)) {
                       return self::_parse($response, $URI);
               } else {
                       return false;
               }
       }

  /**
   * Parses HTML and extracts Open Graph data, this assumes
   * the document is at least well formed.
   *
   * @param $HTML    HTML to parse
   * @return OpenGraph
   */
       static private function _parse($HTML, $URL = '') {
               $old_libxml_error = libxml_use_internal_errors(true);

               $doc = new DOMDocument();
               @$doc->loadHTML($HTML);

               libxml_use_internal_errors($old_libxml_error);

               $base = $URL;
               $bases = $doc->getElementsByTagName('base');
               if ($bases->length > 0) {
                       $href = $bases->item(0)->getAttribute('href');
                       if ($href) {
                               $base = $href;
                       }
               }

		$tags = $doc->getElementsByTagName('meta');
		if (!$tags || $tags->length === 0) {
			return false;
		}

		$page = new self();

		$nonOgDescription = null;
		
               foreach ($tags AS $tag) {
                       $prop = '';
                       if ($tag->hasAttribute('property')) {
                               $prop = $tag->getAttribute('property');
                       } elseif ($tag->hasAttribute('name')) {
                               $prop = $tag->getAttribute('name');
                       }

                       $content = null;
                       if ($tag->hasAttribute('content')) {
                               $content = $tag->getAttribute('content');
                       } elseif ($tag->hasAttribute('value')) {
                               $content = $tag->getAttribute('value');
                       }

                       if (!$prop || $content === null) continue;

                       if (strpos($prop, 'og:') === 0) {
                               $key = strtr(substr($prop, 3), '-', '_');
                               if (strpos($key, 'image') === 0) {
                                       $content = self::resolveUrl($content, $base);
                               }
                               $page->_values[$key] = $content;
                       } elseif (strpos($prop, 'twitter:') === 0) {
                               $tkey = substr($prop, 8);
                               $map = array('title' => 'title', 'description' => 'description', 'image' => 'image', 'url' => 'url');
                               if (isset($map[$tkey]) && !isset($page->_values[$map[$tkey]])) {
                                       if ($map[$tkey] === 'image') {
                                               $content = self::resolveUrl($content, $base);
                                       }
                                       $page->_values[$map[$tkey]] = $content;
                               }
                       } elseif ($prop === 'description') {
                               $nonOgDescription = $content;
                       }
               }
		//Based on modifications at https://github.com/bashofmann/opengraph/blob/master/src/OpenGraph/OpenGraph.php
		if (!isset($page->_values['title'])) {
            $titles = $doc->getElementsByTagName('title');
            if ($titles->length > 0) {
                $page->_values['title'] = $titles->item(0)->textContent;
            }
        }
        if (!isset($page->_values['description']) && $nonOgDescription) {
            $page->_values['description'] = $nonOgDescription;
        }

        //Fallback to use image_src if ogp::image isn't set.
        if (!isset($page->_values['image'])) {
            $domxpath = new DOMXPath($doc);
            $elements = $domxpath->query("//link[@rel='image_src']");

            if ($elements->length > 0) {
                $domattr = $elements->item(0)->attributes->getNamedItem('href');
                if ($domattr) {
                    $abs = self::resolveUrl($domattr->value, $base);
                    $page->_values['image'] = $abs;
                    $page->_values['image_src'] = $abs;
                }
            }
        }

               if (empty($page->_values)) { return false; }

               return $page;
       }

       /**
        * Convert relative URLs to absolute using base URL
        */
       static private function resolveUrl($url, $base) {
               if (empty($url)) return $url;
               if (preg_match('~^https?://~i', $url) || strpos($url, '//') === 0) {
                       return $url;
               }

               // handle root relative
               if (strpos($url, '/') === 0) {
                       $parts = parse_url($base);
                       return $parts['scheme'].'://'.$parts['host'].$url;
               }

               $parts = parse_url($base);
               $path = isset($parts['path']) ? $parts['path'] : '/';
               $dir = preg_replace('#/[^/]*$#', '/', $path);
               return $parts['scheme'].'://'.$parts['host'].$dir.$url;
       }

  /**
   * Helper method to access attributes directly
   * Example:
   * $graph->title
   *
   * @param $key    Key to fetch from the lookup
   */
	public function __get($key) {
		if (array_key_exists($key, $this->_values)) {
			return $this->_values[$key];
		}
		
		if ($key === 'schema') {
			foreach (self::$TYPES AS $schema => $types) {
				if (array_search($this->_values['type'], $types)) {
					return $schema;
				}
			}
		}
	}

  /**
   * Return all the keys found on the page
   *
   * @return array
   */
	public function keys() {
		return array_keys($this->_values);
	}

  /**
   * Helper method to check an attribute exists
   *
   * @param $key
   */
	public function __isset($key) {
		return array_key_exists($key, $this->_values);
	}

  /**
   * Will return true if the page has location data embedded
   *
   * @return boolean Check if the page has location data
   */
	public function hasLocation() {
		if (array_key_exists('latitude', $this->_values) && array_key_exists('longitude', $this->_values)) {
			return true;
		}
		
		$address_keys = array('street_address', 'locality', 'region', 'postal_code', 'country_name');
		$valid_address = true;
		foreach ($address_keys AS $key) {
			$valid_address = ($valid_address && array_key_exists($key, $this->_values));
		}
		return $valid_address;
	}

  /**
   * Iterator code
   */
       private $_position = 0;
       #[\ReturnTypeWillChange]
       public function rewind() { reset($this->_values); $this->_position = 0; }
       #[\ReturnTypeWillChange]
       public function current() { return current($this->_values); }
       #[\ReturnTypeWillChange]
       public function key() { return key($this->_values); }
       #[\ReturnTypeWillChange]
       public function next() { next($this->_values); ++$this->_position; }
       #[\ReturnTypeWillChange]
       public function valid() { return $this->_position < sizeof($this->_values); }
}

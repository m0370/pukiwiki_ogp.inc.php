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

	private static $USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
	private static $REQUEST_HEADERS = array(
		'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
		'Accept-Language: en-US,en;q=0.9,ja;q=0.8'
	);
	private static $CONNECT_TIMEOUT = 5;
	private static $TIMEOUT = 10;

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
		curl_setopt($ch, CURLOPT_TIMEOUT, self::$TIMEOUT);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::$CONNECT_TIMEOUT);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
		curl_setopt($ch, CURLOPT_ENCODING, '');
		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, self::buildRequestHeaders());

		$response = curl_exec($ch);
		$errno = curl_errno($ch);
		$http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
		curl_close($ch);

		if ($errno !== 0 || $http_code >= 400 || empty($response)) {
			return false;
		}
		if (!self::isHtmlContentType($content_type)) {
			return false;
		}

		return self::_parse($response, $URI);
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
				$prop = strtolower($tag->getAttribute('property'));
			} elseif ($tag->hasAttribute('name')) {
				$prop = strtolower($tag->getAttribute('name'));
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
					$content = self::resolveUrl($content, $base, $URL);
				}
				if (!array_key_exists($key, $page->_values)) {
					$page->_values[$key] = $content;
				}
				if (($key === 'image:secure_url' || $key === 'image:url' || $key === 'image_secure_url' || $key === 'image_url') && !isset($page->_values['image'])) {
					$page->_values['image'] = $content;
				}
			} elseif (strpos($prop, 'twitter:') === 0) {
				$tkey = substr($prop, 8);
				$map = array(
					'title' => 'title',
					'description' => 'description',
					'image' => 'image',
					'image:src' => 'image',
					'image:url' => 'image',
					'url' => 'url'
				);
				if (isset($map[$tkey]) && !isset($page->_values[$map[$tkey]])) {
					if ($map[$tkey] === 'image') {
						$content = self::resolveUrl($content, $base, $URL);
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
				$abs = self::resolveUrl($domattr->value, $base, $URL);
				$page->_values['image'] = $abs;
				$page->_values['image_src'] = $abs;
			}
		}
	}

	if (!isset($page->_values['url'])) {
		$domxpath = new DOMXPath($doc);
		$canon = $domxpath->query("//link[@rel='canonical']");
		if ($canon->length > 0) {
			$href = $canon->item(0)->attributes->getNamedItem('href');
			if ($href) {
				$page->_values['url'] = self::resolveUrl($href->value, $base, $URL);
			}
		}
	}

               if (empty($page->_values)) { return false; }

               return $page;
       }

       /**
        * Convert relative URLs to absolute using base URL
        */
	static private function resolveUrl($url, $base, $documentUrl = '') {
		if (empty($url)) return $url;
		if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
			return $url;
		}
		if (strpos($url, '//') === 0) {
			$scheme = self::extractScheme($base);
			if (!$scheme) {
				$scheme = self::extractScheme($documentUrl, 'https');
			}
			return $scheme.':'.$url;
		}

		$normalizedBase = self::normalizeBase($base, $documentUrl);
		if ($normalizedBase === null) {
			return $url;
		}
		$parts = parse_url($normalizedBase);
		if (!$parts || !isset($parts['host'])) {
			return $url;
		}
		$scheme = isset($parts['scheme']) ? $parts['scheme'] : self::extractScheme($documentUrl, 'https');
		$port = isset($parts['port']) ? ':'.$parts['port'] : '';

		if (strpos($url, '/') === 0) {
			return $scheme.'://'.$parts['host'].$port.$url;
		}

		$path = isset($parts['path']) ? $parts['path'] : '/';
		$dir = preg_replace('#/[^/]*$#', '/', $path);
		return $scheme.'://'.$parts['host'].$port.$dir.$url;
	}

	static private function normalizeBase($base, $documentUrl) {
		if (empty($base)) {
			return $documentUrl ?: null;
		}
		if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $base)) {
			return $base;
		}
		if (strpos($base, '//') === 0) {
			$scheme = self::extractScheme($documentUrl, 'https');
			return $scheme.':'.$base;
		}
		if ($documentUrl === '') {
			return null;
		}
		$docParts = parse_url($documentUrl);
		if (!$docParts || !isset($docParts['host'])) {
			return null;
		}
		$scheme = isset($docParts['scheme']) ? $docParts['scheme'] : 'https';
		$port = isset($docParts['port']) ? ':'.$docParts['port'] : '';
		if (strpos($base, '/') === 0) {
			return $scheme.'://'.$docParts['host'].$port.$base;
		}
		$docPath = isset($docParts['path']) ? preg_replace('#/[^/]*$#', '/', $docParts['path']) : '/';
		return $scheme.'://'.$docParts['host'].$port.$docPath.$base;
	}

	static private function extractScheme($url, $default = null) {
		if (!$url) {
			return $default;
		}
		$parts = parse_url($url);
		if ($parts && isset($parts['scheme'])) {
			return $parts['scheme'];
		}
		return $default;
	}

	static private function buildRequestHeaders() {
		return self::$REQUEST_HEADERS;
	}

	static private function isHtmlContentType($contentType) {
		if ($contentType === null || $contentType === false || $contentType === '') {
			return true;
		}
		$contentType = strtolower($contentType);
		return (strpos($contentType, 'text/html') !== false || strpos($contentType, 'application/xhtml+xml') !== false || strpos($contentType, 'text/plain') !== false);
	}

	public static function setUserAgent($userAgent) {
		if (is_string($userAgent)) {
			$userAgent = trim($userAgent);
			if ($userAgent !== '') {
				self::$USER_AGENT = $userAgent;
			}
		}
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

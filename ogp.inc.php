<?php

// 'ogp' plugin for PukiWiki
// author: m0370
// Twitter: @m0370

// ver1.0 (2019.9.10) OGPを取得して表示する機能を実装。
// ver1.1 (2019.9.17) Cache機能を実装。CACHE.DIRのogpというサブフォルダにキャッシュを配置。
// ver1.2 (2020.5.1) 第2引数をスタイルシートとして引用
// ver1.3 (2020.5.2) ファイル形式（GIF・PNGなど）を反映したキャッシュファイル名になるようにしました。従来のキャッシュも利用できます。
// ver 1.4 (2020.7.16) HTMLパース,noimg対応
// ver 1.5 (2021.6.14) キャッシュをJSON形式で保存するように変更
// ver 1.6 (2021.12.23) PHP8.0対応
// ver 1.7 (2023.01.13) 無限ループにならないようにUser Agentでの読み込み防止を実装
// ver 1.8 (2024.03.27) バグ修正
// ver 1.9 (2025.7.19) 機能改善

// WEBPファイルがあるときWEBP表示を試みる fallback
define('PLUGIN_OGP_WEBP_FALLBACK', TRUE); // TRUE, FALSE

// 画像サイズ
define('PLUGIN_OGP_SIZE', 100); // TRUE, FALSE

/////////////////////////////////////////////////

function plugin_ogp_convert()
{
	$args = func_get_args();
	$ogpsize = PLUGIN_OGP_SIZE;
	$is_noimg = false;
	$noimgclass = '';
	$ogpurl = (explode('://', $args[0]));
	$ogpurlmd = md5($ogpurl[1]);
	$cache_prefix = CACHE_DIR . 'ogp/' . $ogpurlmd;
	$datcache = $cache_prefix . '.txt';
	$gifcache = $cache_prefix . '.gif';
	$jpgcache = $cache_prefix . '.jpg';
	$pngcache = $cache_prefix . '.png';
	$webpcache = $cache_prefix . '.webp'; //webp対応
	$browser = $_SERVER['HTTP_USER_AGENT'];

	$existing_imgcache = plugin_ogp_select_existing_cache(array($pngcache, $gifcache, $jpgcache));
	$imgcache = ($existing_imgcache !== null) ? $existing_imgcache : $jpgcache;

	if(file_exists($datcache) && $existing_imgcache !== null) {
		$ogpcache = json_decode(file_get_contents($datcache), true);
		$title = $ogpcache['title'];
		$description = $ogpcache['description'];
		$src = $existing_imgcache;
	} else if($browser !== 'Google Bot') {
		require_once(PLUGIN_DIR.'opengraph.php');
		$graph = OpenGraph::fetch($args[0]);
		if ($graph) {
			$title = $graph->title;
			$description = $graph->description;
			if( isset($graph->{'image:secure_url'}) ){
				$src = $graph->{'image:secure_url'};
			} else {
				$src = $graph->image;
			}
			if( substr($src, 0, 2) === '//'){$src = 'https:' . $src;}

			$detects = array('ASCII','EUC-JP','SJIS','JIS','CP51932','UTF-16','ISO-8859-1');

			// 上記以外でもUTF-8以外の文字コードが渡ってきてた場合、UTF-8に変換する
			if(mb_detect_encoding($title) != 'UTF-8'){
				$title = mb_convert_encoding($title, 'UTF-8', mb_detect_encoding($title, $detects, true));
			}
			if(mb_detect_encoding($description) != 'UTF-8'){
				$description = mb_convert_encoding($description, 'UTF-8', mb_detect_encoding($description, $detects, true));
			}

			$grapharray = array('title' => $title, 'description' => $description, 'src' => $src, 'url' => $args[0], 'date' => date("Y-m-d H:i:s"));
			file_put_contents($datcache, json_encode($grapharray, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

			if($existing_imgcache !== null) {
				$src = $existing_imgcache;
			} else if($src == '') {
				$is_noimg = true;
				touch($jpgcache);
			} else {
				$imgfile = @file_get_contents($src);
				if($imgfile === false) {
					$is_noimg = true;
					touch($jpgcache);
				} else {
					$image_info = @getimagesizefromstring($imgfile);
					$filetype = is_array($image_info) && isset($image_info[2]) ? $image_info[2] : null;
					$stored_cache = null;
					$gd_image = null;
					$webp_created = false;

					if($filetype === IMAGETYPE_GIF) {
						$stored_cache = $gifcache;
					} else if ($filetype === IMAGETYPE_PNG) {
						$stored_cache = $pngcache;
					} else if ($filetype === IMAGETYPE_JPEG) {
						$stored_cache = $jpgcache;
					}

					if($filetype === IMAGETYPE_WEBP && PLUGIN_OGP_WEBP_FALLBACK) {
						file_put_contents($webpcache, $imgfile);
						$webp_created = true;
					}

					if($filetype === IMAGETYPE_WEBP) {
						$gd_image = plugin_ogp_image_from_string($imgfile);
						if($gd_image && function_exists('imagejpeg')) {
							if(imagejpeg($gd_image, $jpgcache, 90)) {
								$stored_cache = $jpgcache;
							}
						}
					} else if($stored_cache !== null) {
						file_put_contents($stored_cache, $imgfile);
					}

					if($stored_cache === null) {
						$stored_cache = $jpgcache;
						file_put_contents($stored_cache, $imgfile);
					}

					if(PLUGIN_OGP_WEBP_FALLBACK && !$webp_created) {
						if($gd_image === null) {
							$gd_image = plugin_ogp_image_from_string($imgfile);
						}
						if($gd_image && function_exists('imagewebp')) {
							if(imagewebp($gd_image, $webpcache, 80)) {
								$webp_created = true;
							}
						}
						if(!$webp_created && file_exists($webpcache)) {
							unlink($webpcache);
						}
					}

					if($gd_image) {
						imagedestroy($gd_image);
					}

					$imgcache = $stored_cache;
					$existing_imgcache = $stored_cache;
					$src = $stored_cache;
				}
			}
		} else return '#ogp Error: Page not found.';
	} else return false;

	if (!$is_noimg) {
		$is_noimg = (in_array('noimg', $args) || ( $existing_imgcache !== null && file_exists($existing_imgcache) && filesize($existing_imgcache) <= 1 ));
	}
	if($is_noimg) {$noimgclass = "ogp-noimg" ;}

//XSS回避
	$description = htmlspecialchars($description);
	$title = htmlspecialchars($title);
	$args[0] = htmlspecialchars($args[0]);

//WEBP表示のfallback
	if ( PLUGIN_OGP_WEBP_FALLBACK && file_exists($webpcache)) {
		$fallback1 = '<picture><source type="image/webp" srcset="' . $webpcache . '"/>';
		$fallback2 = '</picture>';
	} else {
		$fallback1 = '';
		$fallback2 = '';
	}

	return <<<EOD
<div class="ogp">
<div class="ogp-img-box $noimgclass">$fallback1<img class="ogp-img" src="$src" loading="lazy" alt="$title" width="$ogpsize" height="$ogpsize">$fallback2</div>
<div class="ogp-title"><a href="$args[0]" target="_blank" rel="noreferrer">$title<span class="overlink"></span></a></div>
<div class="ogp-description">$description</div>
<div class="ogp-url">$args[0]</div>
</div>
EOD;
}

function plugin_ogp_select_existing_cache($paths)
{
	foreach($paths as $path) {
		if($path !== null && file_exists($path)) {
			return $path;
		}
	}
	return null;
}

function plugin_ogp_image_from_string($binary)
{
	if(!function_exists('imagecreatefromstring')) {
		return null;
	}
	$image = @imagecreatefromstring($binary);
	if($image === false) {
		return null;
	}
	if(function_exists('imageistruecolor') && function_exists('imagepalettetotruecolor') && !imageistruecolor($image)) {
		imagepalettetotruecolor($image);
	}
	if(function_exists('imagesavealpha')) {
		imagealphablending($image, true);
		imagesavealpha($image, true);
	}
	return $image;
}

?>

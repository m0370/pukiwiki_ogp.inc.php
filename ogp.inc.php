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
	$ogpurl = (explode('://', $args[0]));
	$ogpurlmd = md5($ogpurl[1]);
	$datcache = CACHE_DIR . 'ogp/' . $ogpurlmd . '.txt';
	$gifcache = CACHE_DIR . 'ogp/' . $ogpurlmd . '.gif';
	$jpgcache = CACHE_DIR . 'ogp/' . $ogpurlmd . '.jpg';
	$pngcache = CACHE_DIR . 'ogp/' . $ogpurlmd . '.png';
	if(PLUGIN_OGP_WEBP_FALLBACK) {
	$webpcache = CACHE_DIR . 'ogp/' . $ogpurlmd . '.webp'; //webp対応
	}
	$browser = $_SERVER['HTTP_USER_AGENT'];
	
	if(file_exists($pngcache)) { $imgcache = $pngcache ; }
	else if(file_exists($gifcache)) { $imgcache = $gifcache ; }
	else { $imgcache = $jpgcache ; }
	
	if(file_exists($datcache) && file_exists($imgcache)) {
		$ogpcache = json_decode(file_get_contents($datcache), true);
		$title = $ogpcache['title'];
		$description = $ogpcache['description'];
		$src = $imgcache ;
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

			if(file_exists($imgcache)) {
				$src = $imgcache ;
                        } else if($src == '') {
                                $is_noimg = true;
                                touch($jpgcache);
			} else {
				$imgfile = file_get_contents($src);
				$filetype = exif_imagetype($src);
				if( $filetype == IMAGETYPE_GIF ){
					file_put_contents($gifcache, $imgfile) ;
				} else if ( $filetype == IMAGETYPE_PNG ){
					file_put_contents($pngcache, $imgfile) ;
				} else {
					file_put_contents($jpgcache, $imgfile) ;
				} //どの拡張子でもない場合、ダミーjpgファイルを作る
			}
		} else return '#ogp Error: Page not found.';
	} else return false;

       if (!$is_noimg) {
               $is_noimg = (in_array('noimg', $args) || ( file_exists($imgcache) && filesize($imgcache) <= 1 ));
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
?>

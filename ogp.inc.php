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
// ver 2.0 (2025.7.19) HTTPクライアントとWebP fallbackを含む機能改善
// ver 3.0 (2026.7.8) エラーキャッシュ・取得失敗時のプレーンリンク表示・repairモード（ブックマークレットによるキャッシュ修復）を追加

// WEBPファイルがあるときWEBP表示を試みる fallback
define('PLUGIN_OGP_WEBP_FALLBACK', TRUE); // TRUE, FALSE

// 画像サイズ
define('PLUGIN_OGP_SIZE', 100); // default=100

// OGP取得時のHTTP User Agentを任意の文字列に差し替えたい場合はここに指定
define('PLUGIN_OGP_HTTP_USER_AGENT', '');

// OGPフェッチをスキップするドメイン (ボット拒否が確実なサイトなど)
// 追加方法: ホスト名を文字列で配列に追加する 例: ['www.example.org', 'example.net']
define('PLUGIN_OGP_SKIP_DOMAINS', []);

// repairモード (?plugin=ogp) を有効にする。管理者パスワード($adminpass)が必要
define('PLUGIN_OGP_REPAIR', TRUE); // TRUE, FALSE

// repairモードのブックマークレット用トークンの有効期間 (秒)
define('PLUGIN_OGP_REPAIR_TOKEN_TTL', 7200);

/////////////////////////////////////////////////

function plugin_ogp_convert()
{
	$args = func_get_args();
	$ogpsize = PLUGIN_OGP_SIZE;
	$is_noimg = false;
	$noimgclass = '';
	$ogpurlmd = plugin_ogp_build_cache_key($args[0]);
	if($ogpurlmd === null) {
		return '#ogp Error: Invalid URL.';
	}

	// ドメインスキップリスト
	$_url_host = strtolower((string)(@parse_url($args[0], PHP_URL_HOST)));
	if($_url_host !== '' && in_array($_url_host, PLUGIN_OGP_SKIP_DOMAINS, true)) {
		return plugin_ogp_fallback_link($args[0]);
	}

	$cache_prefix = CACHE_DIR . 'ogp/' . $ogpurlmd;
	$datcache = $cache_prefix . '.txt';
	$gifcache = $cache_prefix . '.gif';
	$jpgcache = $cache_prefix . '.jpg';
	$pngcache = $cache_prefix . '.png';
	$webpcache = $cache_prefix . '.webp'; //webp対応
	$browser = $_SERVER['HTTP_USER_AGENT'];

	$existing_imgcache = plugin_ogp_select_existing_cache(array($pngcache, $gifcache, $jpgcache));
	$imgcache = ($existing_imgcache !== null) ? $existing_imgcache : $jpgcache;

	$ogpcache = null;
	if(file_exists($datcache)) {
		$ogpcache = json_decode(file_get_contents($datcache), true);
	}

	// フェッチ失敗キャッシュ: 30日間リトライ抑制 (期限切れなら再取得へ)
	if(is_array($ogpcache) && !empty($ogpcache['error'])) {
		if(isset($ogpcache['date']) && strtotime($ogpcache['date']) > strtotime('-30 days')) {
			return plugin_ogp_fallback_link($args[0]);
		}
		$ogpcache = null;
	}

	if(is_array($ogpcache) && isset($ogpcache['title']) && $existing_imgcache !== null) {
		$title = $ogpcache['title'];
		$description = isset($ogpcache['description']) ? $ogpcache['description'] : '';
		$src = $existing_imgcache;
		plugin_ogp_try_create_webp_from_cache($existing_imgcache, $webpcache);
        } else if($browser !== 'Google Bot') {
                require_once(PLUGIN_DIR.'opengraph.php');
                if(defined('PLUGIN_OGP_HTTP_USER_AGENT') && PLUGIN_OGP_HTTP_USER_AGENT !== '') {
                        OpenGraph::setUserAgent(PLUGIN_OGP_HTTP_USER_AGENT);
                }
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
				$imgfile = plugin_ogp_fetch_image($src);
				$stored_cache = ($imgfile === false) ? null : plugin_ogp_store_image($imgfile, $cache_prefix);
				if($stored_cache === null) {
					$is_noimg = true;
					touch($jpgcache);
				} else {
					$imgcache = $stored_cache;
					$existing_imgcache = $stored_cache;
					$src = $stored_cache;
				}
			}
		} else {
			// 取得失敗: エラーキャッシュを書いてプレーンリンクを表示
			file_put_contents($datcache, json_encode(array('error' => true, 'url' => $args[0], 'date' => date('Y-m-d H:i:s')), JSON_UNESCAPED_UNICODE));
			return plugin_ogp_fallback_link($args[0]);
		}
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

// OGPを取得できないURLでもリンクを消さず、プレーンなリンクカードとして表示する
function plugin_ogp_fallback_link($url)
{
	if(!is_string($url) || trim($url) === '') {
		return '';
	}
	$disp = preg_replace('#^https?://#', '', trim($url));
	if(mb_strlen($disp) > 70) {
		$disp = mb_substr($disp, 0, 67) . '...';
	}
	$url_h = htmlspecialchars($url);
	$disp_h = htmlspecialchars($disp);
	return <<<EOD
<div class="ogp ogp-fallback">
<div class="ogp-title"><a href="$url_h" target="_blank" rel="noreferrer">$disp_h<span class="overlink"></span></a></div>
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

function plugin_ogp_build_cache_key($url)
{
	if(!is_string($url)) {
		return null;
	}
	$url = trim($url);
	if($url === '') {
		return null;
	}
	$parsed = @parse_url($url);
	if($parsed === false) {
		return md5($url);
	}
	$key = '';
	if(isset($parsed['host'])) {
		$key .= strtolower($parsed['host']);
		if(isset($parsed['port'])) {
			$key .= ':' . $parsed['port'];
		}
	}
	if(isset($parsed['path'])) {
		$key .= $parsed['path'];
	}
	if(isset($parsed['query'])) {
		$key .= '?' . $parsed['query'];
	}
	if(isset($parsed['fragment'])) {
		$key .= '#' . $parsed['fragment'];
	}
	if($key === '') {
		if(isset($parsed['scheme'])) {
			$key = $parsed['scheme'];
		} else if(isset($parsed['path'])) {
			$key = $parsed['path'];
		} else {
			$key = $url;
		}
	}
	return md5($key);
}

function plugin_ogp_try_create_webp_from_cache($source_path, $webpcache)
{
	if(!PLUGIN_OGP_WEBP_FALLBACK || $source_path === null || file_exists($webpcache)) {
		return false;
	}
	if(!file_exists($source_path)) {
		return false;
	}
	$binary = @file_get_contents($source_path);
	if($binary === false) {
		return false;
	}
	$gd_image = plugin_ogp_image_from_string($binary);
	if(!$gd_image || !function_exists('imagewebp')) {
		if($gd_image) {
			imagedestroy($gd_image);
		}
		return false;
	}
	$result = imagewebp($gd_image, $webpcache, 80);
	imagedestroy($gd_image);
	if(!$result && file_exists($webpcache)) {
		unlink($webpcache);
	}
	return $result;
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

function plugin_ogp_fetch_image($url)
{
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
	curl_setopt($ch, CURLOPT_TIMEOUT, 15);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
	curl_setopt($ch, CURLOPT_USERAGENT, defined('PLUGIN_OGP_HTTP_USER_AGENT') && PLUGIN_OGP_HTTP_USER_AGENT !== '' ? PLUGIN_OGP_HTTP_USER_AGENT : 'Mozilla/5.0');
	$response = curl_exec($ch);
	$errno = curl_errno($ch);
	$http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if($errno !== 0 || $http_code >= 400 || $response === false || $response === '') {
		return false;
	}
	return $response;
}

// 画像バイナリを形式判定して保存し、保存先パスを返す (画像として不正なら null)
// GIF/PNG/JPEG は元形式のまま、WebP は JPEG に変換して保存。WebPフォールバックも生成する
function plugin_ogp_store_image($imgfile, $cache_prefix)
{
	$gifcache = $cache_prefix . '.gif';
	$jpgcache = $cache_prefix . '.jpg';
	$pngcache = $cache_prefix . '.png';
	$webpcache = $cache_prefix . '.webp';

	$image_info = @getimagesizefromstring($imgfile);
	$filetype = is_array($image_info) && isset($image_info[2]) ? $image_info[2] : null;
	if($filetype === null) {
		return null;
	}
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
		if($gd_image) {
			imagedestroy($gd_image);
		}
		return null;
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

	return $stored_cache;
}

/////////////////////////////////////////////////
// repairモード (?plugin=ogp)
//
// サーバーからのOGP取得はbot検知やJSレンダリング必須サイトで失敗することがある。
// repairモードでは、管理者が自分のブラウザで実ページを開き、ブックマークレットで
// メタタグを抽出してこのWikiにPOSTすることで、サーバー側キャッシュを修復できる。
// ブラウザ自身が取得エンジンになるため、bot検知もJSレンダリングも自然に通過する。
//
// セキュリティ設計:
// - 管理ページはPukiWiki管理者パスワード($adminpass)で認証 (pkwk_login)
// - ブックマークレットからのクロスサイトPOSTにはセッションCookieが付かないため
//   (SameSite=Lax)、認証はブックマークレットに埋め込むベアラートークンで行う
// - トークンは random_bytes(16)。サーバーにはsha256ハッシュのみ保存し、
//   有効期限 (PLUGIN_OGP_REPAIR_TOKEN_TTL) 付きで hash_equals() で照合する
// - 画像バイナリはクライアントから受け取らず、URLのみ受けてサーバー側cURLで
//   取得・画像形式検証する (キャッシュポイズニング対策)

function plugin_ogp_action()
{
	global $vars, $adminpass;

	if (PKWK_READONLY) die_message('PKWK_READONLY prohibits this');
	if (!defined('PLUGIN_OGP_REPAIR') || !PLUGIN_OGP_REPAIR) {
		return array('msg' => 'OGP cache repair', 'body' => '<p>repairモードは無効化されています (PLUGIN_OGP_REPAIR)。</p>');
	}
	if (!isset($adminpass) || $adminpass === '' || $adminpass === '{x-php-md5}!') {
		return array('msg' => 'OGP cache repair', 'body' => '<p>管理者パスワード ($adminpass) が設定されていないため、repairモードは利用できません。</p>');
	}

	$mode = isset($vars['mode']) ? (string)$vars['mode'] : '';
	if ($mode === 'submit') {
		return plugin_ogp_repair_submit();
	}
	return plugin_ogp_repair_page();
}

function plugin_ogp_repair_session_start()
{
	if (session_status() === PHP_SESSION_NONE) {
		@session_start();
	}
}

function plugin_ogp_repair_token_file()
{
	return CACHE_DIR . 'ogp/.repair_token';
}

function plugin_ogp_repair_issue_token()
{
	$token = bin2hex(random_bytes(16));
	$dir = CACHE_DIR . 'ogp';
	if (!is_dir($dir)) {
		@mkdir($dir, 0755, true);
	}
	$file = plugin_ogp_repair_token_file();
	file_put_contents($file, json_encode(array(
		'hash' => hash('sha256', $token),
		'expires' => time() + PLUGIN_OGP_REPAIR_TOKEN_TTL,
	)));
	@chmod($file, 0600);
	$_SESSION['plugin_ogp_repair_token'] = $token;
	return $token;
}

function plugin_ogp_repair_check_token($token)
{
	if (!is_string($token) || $token === '') {
		return false;
	}
	$file = plugin_ogp_repair_token_file();
	if (!file_exists($file)) {
		return false;
	}
	$data = json_decode(file_get_contents($file), true);
	if (!is_array($data) || empty($data['hash']) || empty($data['expires'])) {
		return false;
	}
	if (time() > (int)$data['expires']) {
		return false;
	}
	return hash_equals((string)$data['hash'], hash('sha256', $token));
}

// cache/ogp/*.txt からエラーキャッシュ ({"error":true}) を列挙する
function plugin_ogp_repair_list_errors()
{
	$list = array();
	foreach (glob(CACHE_DIR . 'ogp/*.txt') as $file) {
		$data = @json_decode((string)@file_get_contents($file), true);
		if (is_array($data) && !empty($data['error']) && isset($data['url']) && is_string($data['url'])) {
			$list[] = array('url' => $data['url'], 'date' => isset($data['date']) ? (string)$data['date'] : '');
		}
	}
	usort($list, function($a, $b) { return strcmp($b['date'], $a['date']); });
	return $list;
}

function plugin_ogp_repair_script_uri()
{
	if (function_exists('get_script_uri')) {
		return get_script_uri();
	}
	global $script;
	return $script;
}

// ブックマークレット (送信/コピー) のjavascript:コードを生成する
function plugin_ogp_repair_bookmarklets($script_uri, $token)
{
	// メタタグ抽出部 (共通): og:* → twitter:* → <title> の優先順。
	// location.hash の #ogpRepair=... があればそれを元URLとして使う
	// (短縮URL等のリダイレクトでもキャッシュキーの元URLを保つため)
	$extract = "var d=document,q=function(s,a){var e=d.querySelector(s);return e?(e.getAttribute(a||'content')||'').trim():''},og=function(p){return q('meta[property=\"og:'+p+'\"]')||q('meta[name=\"og:'+p+'\"]')},tw=function(p){return q('meta[name=\"twitter:'+p+'\"]')||q('meta[property=\"twitter:'+p+'\"]')},m=location.hash.match(/ogpRepair=([^&]+)/),u=m?decodeURIComponent(m[1]):location.href.split('#')[0],t=og('title')||tw('title')||(d.title||'').trim(),ds=og('description')||tw('description')||q('meta[name=\"description\"]'),im=og('image:secure_url')||og('image')||tw('image')||q('link[rel=\"image_src\"]','href');";

	$send = "javascript:(function(){" . $extract
		. "if(!t){alert('OGP data not found');return}"
		. "var f=d.createElement('form');f.method='POST';f.action='{SCRIPT}';"
		. "var add=function(n,v){var i=d.createElement('input');i.type='hidden';i.name=n;i.value=v;f.appendChild(i)};"
		. "add('plugin','ogp');add('mode','submit');add('token','{TOKEN}');"
		. "add('url',u);add('title',t);add('description',ds);add('image',im);"
		. "d.body.appendChild(f);f.submit();})();";

	$copy = "javascript:(function(){" . $extract
		. "var j=JSON.stringify({url:u,title:t,description:ds,image:im});"
		. "if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(j).then(function(){alert('Copied. Paste it into the repair page.')},function(){prompt('Copy this JSON:',j)})}else{prompt('Copy this JSON:',j)}})();";

	return array(
		'send' => str_replace(array('{SCRIPT}', '{TOKEN}'), array($script_uri, $token), $send),
		'copy' => $copy,
	);
}

function plugin_ogp_repair_login_form($message = '')
{
	$script_h = htmlspecialchars(plugin_ogp_repair_script_uri());
	$msg_h = ($message !== '') ? '<p><strong>' . htmlspecialchars($message) . '</strong></p>' : '';
	$body = <<<EOD
$msg_h
<p>OGPキャッシュの修復モードです。管理者パスワードでログインしてください。</p>
<form method="post" action="$script_h">
<input type="hidden" name="plugin" value="ogp">
<input type="password" name="pass" size="24">
<input type="submit" value="ログイン">
</form>
EOD;
	return array('msg' => 'OGP cache repair', 'body' => $body);
}

function plugin_ogp_repair_page()
{
	global $post;
	plugin_ogp_repair_session_start();
	$authed = !empty($_SESSION['plugin_ogp_repair_auth']);

	// ログイン処理
	if (!$authed && isset($post['pass'])) {
		if (pkwk_login($post['pass'])) {
			$_SESSION['plugin_ogp_repair_auth'] = true;
			$authed = true;
			plugin_ogp_repair_issue_token();
		} else {
			return plugin_ogp_repair_login_form('パスワードが違います。');
		}
	}
	if (!$authed) {
		return plugin_ogp_repair_login_form();
	}

	// ログアウト / トークン再発行
	if (isset($post['ogp_logout'])) {
		unset($_SESSION['plugin_ogp_repair_auth'], $_SESSION['plugin_ogp_repair_token']);
		@unlink(plugin_ogp_repair_token_file());
		return plugin_ogp_repair_login_form('ログアウトしました。');
	}
	if (isset($post['ogp_renew']) || empty($_SESSION['plugin_ogp_repair_token']) || !plugin_ogp_repair_check_token($_SESSION['plugin_ogp_repair_token'])) {
		plugin_ogp_repair_issue_token();
	}

	$token = $_SESSION['plugin_ogp_repair_token'];
	$script_uri = plugin_ogp_repair_script_uri();
	$script_h = htmlspecialchars($script_uri);
	$token_h = htmlspecialchars($token);
	$bm = plugin_ogp_repair_bookmarklets($script_uri, $token);
	$bm_send_h = htmlspecialchars($bm['send']);
	$bm_copy_h = htmlspecialchars($bm['copy']);
	$ttl_hours = round(PLUGIN_OGP_REPAIR_TOKEN_TTL / 3600, 1);

	// エラーキャッシュ一覧
	$errors = plugin_ogp_repair_list_errors();
	$rows = '';
	foreach ($errors as $e) {
		$u_h = htmlspecialchars($e['url']);
		$open_h = htmlspecialchars($e['url'] . '#ogpRepair=' . rawurlencode($e['url']));
		$d_h = htmlspecialchars($e['date']);
		$rows .= "<tr><td><a href=\"$open_h\" target=\"_blank\" rel=\"noopener noreferrer\">開く</a></td><td>$u_h</td><td>$d_h</td></tr>\n";
	}
	$count = count($errors);
	$table = ($count > 0)
		? "<table border=\"1\"><tr><th></th><th>URL</th><th>失敗日時</th></tr>\n$rows</table>"
		: '<p>エラーキャッシュはありません。</p>';

	$body = <<<EOD
<h3>使い方</h3>
<ol>
<li>下の2つのブックマークレットをブックマークバーにドラッグして登録する（初回のみ）</li>
<li>一覧の「開く」をクリックして対象ページを新しいタブで開く</li>
<li>開いたページ上で「OGP送信」ブックマークレットをクリックすると、メタ情報がこのWikiに送信されキャッシュが修復される</li>
<li>送信がブロックされるサイト（CSPが厳しいサイト）では「OGPコピー」を使い、下の貼り付け欄から修復する</li>
</ol>
<p><strong>ブックマークレット:</strong>
<a href="$bm_send_h">OGP送信</a> ／
<a href="$bm_copy_h">OGPコピー</a>
（トークン有効期間: 約{$ttl_hours}時間。期限切れ時は再発行して登録し直す）</p>
<form method="post" action="$script_h" style="display:inline">
<input type="hidden" name="plugin" value="ogp">
<input type="hidden" name="ogp_renew" value="1">
<input type="submit" value="トークン再発行">
</form>
<form method="post" action="$script_h" style="display:inline">
<input type="hidden" name="plugin" value="ogp">
<input type="hidden" name="ogp_logout" value="1">
<input type="submit" value="ログアウト">
</form>

<h3>取得に失敗しているURL（{$count}件）</h3>
$table

<h3>任意のURLを修復・更新</h3>
<p>エラーになっていないURLのカードを更新したい場合もここから開けます。</p>
<p><input type="text" id="_ogp_repair_url" size="60" placeholder="https://...">
<button type="button" onclick="var u=document.getElementById('_ogp_repair_url').value.trim();if(u){window.open(u+'#ogpRepair='+encodeURIComponent(u),'_blank')}">開く</button></p>

<h3>貼り付けで修復（OGPコピー用）</h3>
<form method="post" action="$script_h">
<input type="hidden" name="plugin" value="ogp">
<input type="hidden" name="mode" value="submit">
<input type="hidden" name="token" value="$token_h">
<p><textarea name="payload" rows="4" cols="80" placeholder='{"url":"...","title":"...","description":"...","image":"..."}'></textarea></p>
<p><input type="submit" value="貼り付けデータで修復"></p>
</form>
EOD;

	return array('msg' => 'OGP cache repair', 'body' => $body);
}

function plugin_ogp_repair_submit()
{
	global $post;

	$back = '<p><a href="' . htmlspecialchars(plugin_ogp_repair_script_uri()) . '?plugin=ogp">修復ページに戻る</a></p>';
	$fail = function($message) use ($back) {
		return array('msg' => 'OGP cache repair', 'body' => '<p><strong>' . htmlspecialchars($message) . '</strong></p>' . $back);
	};

	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
		return $fail('POSTメソッドで送信してください。');
	}
	$token = isset($post['token']) ? $post['token'] : '';
	if (!plugin_ogp_repair_check_token($token)) {
		return $fail('トークンが無効か期限切れです。修復ページで再ログインし、ブックマークレットを登録し直してください。');
	}

	// 貼り付け (JSON) または ブックマークレットの個別フィールド
	$url = ''; $title = ''; $description = ''; $image = '';
	if (!empty($post['payload'])) {
		$data = json_decode($post['payload'], true);
		if (!is_array($data)) {
			return $fail('貼り付けデータをJSONとして解釈できませんでした。');
		}
		$url = isset($data['url']) ? (string)$data['url'] : '';
		$title = isset($data['title']) ? (string)$data['title'] : '';
		$description = isset($data['description']) ? (string)$data['description'] : '';
		$image = isset($data['image']) ? (string)$data['image'] : '';
	} else {
		$url = isset($post['url']) ? (string)$post['url'] : '';
		$title = isset($post['title']) ? (string)$post['title'] : '';
		$description = isset($post['description']) ? (string)$post['description'] : '';
		$image = isset($post['image']) ? (string)$post['image'] : '';
	}

	$url = trim($url);
	$title = mb_substr(trim($title), 0, 500);
	$description = mb_substr(trim($description), 0, 2000);
	$image = trim($image);

	if (!preg_match('#^https?://#i', $url)) {
		return $fail('URLが不正です (http/https のみ)。');
	}
	if ($title === '') {
		return $fail('タイトルが空です。');
	}
	if ($image !== '' && !preg_match('#^https?://#i', $image)) {
		$image = '';
	}

	$key = plugin_ogp_build_cache_key($url);
	if ($key === null) {
		return $fail('キャッシュキーを生成できませんでした。');
	}
	$cache_prefix = CACHE_DIR . 'ogp/' . $key;

	// 旧画像キャッシュを削除 (形式が変わっても古い画像が優先されないように)
	foreach (array('.jpg', '.png', '.gif', '.webp') as $ext) {
		if (file_exists($cache_prefix . $ext)) {
			@unlink($cache_prefix . $ext);
		}
	}

	// 画像はURLのみ受け取り、サーバー側で取得・画像形式を検証する
	$noimg = true;
	if ($image !== '') {
		$imgfile = plugin_ogp_fetch_image($image);
		if ($imgfile !== false && plugin_ogp_store_image($imgfile, $cache_prefix) !== null) {
			$noimg = false;
		}
	}
	if ($noimg) {
		touch($cache_prefix . '.jpg');
	}

	$grapharray = array(
		'title' => $title,
		'description' => $description,
		'src' => $noimg ? '' : $image,
		'url' => $url,
		'date' => date('Y-m-d H:i:s'),
	);
	file_put_contents($cache_prefix . '.txt', json_encode($grapharray, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

	$url_h = htmlspecialchars($url);
	$title_h = htmlspecialchars($title);
	$img_note = $noimg ? '（画像なし）' : '';
	$body = <<<EOD
<p>キャッシュを修復しました$img_note: <a href="$url_h" target="_blank" rel="noopener noreferrer">$title_h</a></p>
$back
EOD;
	return array('msg' => 'OGP cache repair', 'body' => $body);
}

?>

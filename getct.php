<?php
/*
 * Stahování videí ze stránek České televize
 *
 * (C) 2011-2015	David Štancl
 *
 * Historie:
 * - 2015-01-02
 * 	- nový způsob: pomocí VLC
 * 	- stahování pomocí ffmpeg
 *
 * - 2014-02-18
 * 	- přidáno získání dat AJAXem (místo SOAP)
 *
 * - 2014-02-04
 * 	- změna v ČT: vytažení adresy videa z URI
 *
 * - 2013-05-27
 *	- bug-fix: zjištění údajů o videu - převod %26 na &
 *
 * - 01.10.2012
 * 	- add: automatický název videa (podle názvu stahovaného souboru)
 * 	- verze povýšena na 1.16
 *
 * - 13.08.2012
 * 	- add: parametr -vv (verbose i pro RTMPdump)
 * 	- změna verze na 1.15
 *
 * - 25.06.2012
 * 	- bug-fix: doplnění parametru (verze HTTP protokolu)
 *
 * - 24.05.2012
 * 	- doplněny údaje v hlavičce (změna požadavků iVysílání)
 * 	- zprovozněno stahování článků
 * 	- změněna verze na 1.13
 *
 * - 13.03.2012
 * 	- doplněn parametr pro simulaci (výpis, co by se dělo)
 * 	- doplněn parametr pro --live stahování
 * 	- doplněn parametr pro opakování stahování základní stránky
 *
 * - 21.12.2011
 * 	- možnost stahovat vnořená videa
 *
 * - 01.10.2011
 *   - přidáno stahování videí z článků
 *
 * - 30.05.2011
 *   - první verze
 */
$VERSION = '1.17';

/**
 * Načtení z URL
 *
 * @param	string	$url	Adresa
 * @param	string	$post	POST požadavek
 * @return	string	Obsah souboru
 */
function getFromURL($url, $post = NULL) {
    $retval = '';
    $c = curl_init($url);
    curl_setopt($c, CURLOPT_HEADER, 0);	// Neposílat hlavičky
    curl_setopt ($c, CURLOPT_TIMEOUT, 5);	// Timeout 5s
    curl_setopt ($c, CURLOPT_USERAGENT, 'Mozilla/5.O');	// Browser
    curl_setopt ($c, CURLOPT_HTTPHEADER, array('X-Requested-With: XMLHttpRequest', 'x-addr: 127.0.0.1'));
    curl_setopt ($c, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
    curl_setopt ($c, CURLOPT_FOLLOWLOCATION, true);
    if ($post) {
	curl_setopt ($c, CURLOPT_POSTFIELDS, $post);
	curl_setopt ($c, CURLOPT_POST, TRUE);
    }
    ob_start();
    curl_exec ($c);
    $retval = ob_get_contents();
    ob_end_clean ();
    $msg = NULL;
    if (($errno = curl_errno($c)) != CURLE_OK) {
	// Došlo k chybě
	$msg = $errno.' ('.curl_error($c).')';
    }
    curl_close ($c);
    if ($msg != NULL)
	throw new Exception ('CURL error: '.$msg);
    
    return $retval;
}

/**
 * Zjištění URL videa z článku
 *
 * @param	string	$url	Adresa
 * @return	string	URL videa
 */
function getVideoURLFromArticle($url) {
    // Úprava URL
    if (preg_match('/void\(q=\'([^\']*)/', $url, $m)) {
	$url = $m[1];
    }
    $retval = '';
    $post = array(
	'cmd' => 'getVideoPlayerUrl',
	'q' => $url,
	'autoStart' => 'true',
    );
    // Přidání dalších údajů k URL
    $baseURL = 'http://www.ceskatelevize.cz/ct24/ajax/';
    $url = $baseURL;
    $delim = '?';
    $postURL = '';
    foreach ($post as $key => $val) {
	$url .= $delim.$key.'='.$val;
	$postURL .= '&'.$key.'='.urlencode($val);
	$delim = '&';
    }
    $url = $baseURL;
    $c = curl_init($url);
    curl_setopt($c, CURLOPT_HEADER, 0);	// Neposílat hlavičky
    curl_setopt ($c, CURLOPT_TIMEOUT, 10);	// Timeout 10 s
    curl_setopt ($c, CURLOPT_USERAGENT, 'Mozilla/4.O');	// Browser
    curl_setopt ($c, CURLOPT_POST, TRUE);
    curl_setopt ($c, CURLOPT_POSTFIELDS, substr($postURL, 1));
    curl_setopt ($c, CURLOPT_FOLLOWLOCATION, TRUE);
    curl_setopt ($c, CURLOPT_HTTPHEADER, array('X-Requested-With: XMLHttpRequest', 'x-addr: 127.0.0.1'));

    //curl_setopt ($c, CURLOPT_REFERER, 'http://www.ceskatelevize.cz/ct24/regiony/170512-karluv-most-skryva-dechberouci-dutiny-dostanou-se-do-nich-pouze-udrzbari/');

    ob_start();
    curl_exec ($c);
    $retval = ob_get_contents();
    ob_end_clean ();
    $msg = NULL;
    if (($errno = curl_errno($c)) != CURLE_OK) {
	// Došlo k chybě
	$msg = $errno.' ('.curl_error($c).')';
    }
    curl_close ($c);
    if ($msg != NULL)
	throw new Exception ('CURL error: '.$msg);

    // Úprava výsledku (převod z JSON)
    if (preg_match('/"videoPlayerUrl":"([^"]*)"/', $retval, $m)) {
	$retval = $m[1];
	$retval = str_replace('\\', '', $retval);
    }
    return $retval;
}

/**
 * Zjištění obsahu z URL
 *
 * Načte stránku podle zadaného URL. Pokud stránka obsahuje iframe
 * s videem, načte obsah vloženého rámce.
 *
 * @param	string	$url	URL stránky
 * @return	string	obsah stránky
 */
function getContentFromURL($url)
{
    if (strpos($url, 'http:') === false)
	$url = 'http://www.ceskatelevize.cz'.$url;
    $content = getFromURL($url);
    $m = array();
    if (preg_match('/.*<iframe\s+src="(http:\/\/www.ceskatelevize.cz\/ivysilani\/embed\/iFramePlayerCT24.php[^"]*)".*/', $content, $m))
    {
	$url = str_replace('&amp;', '&', $m[1]);
	$content = getFromURL($url);
    }
    return $content;
}

/**
 * Získání volání (SOAP)
 *
 * @param	string	$content	Obsah souboru
 * @return	string	SOAP
 */
function getSOAP($content) {
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
	$m = array();
	if (preg_match ('/callSOAP\s*\((.*)\);/', $line, $m)) {
	    return $m[1];
	}
    } // foreach
    throw new Exception ('SOAP not found');
}

/**
 * Pomocná funkce pro průchod polem
 *
 * @param	mixed	$item	Položka
 * @param	string	$path	Cesta
 */
function _walk ($item, $path = '') {
    $r = array();
    if (is_array($item)) {
	foreach ($item as $k => $it) {
	    $r[] = _walk($it, $path.'['.$k.']');
	}
    } else {
	$r[] = $path.'='.urlencode($item);
    }
    return implode('&', $r);
}

/**
 * Zjištění parametrů
 *
 * @param	string	$soap	SOAP volání (jako řetězec)
 * @return	string	POST požadavek
 */
function SOAP2POST($soap) {
    $json = json_decode($soap, TRUE);
    if ($json === NULL)
	throw new Exception ('Cannot decode JSON');
    if (isset($json['options'])) {
	$retval = _walk($json['options'], 'options');
    } else {
	throw new Exception ('Bad JSON data (no options key)');
    }
    return str_replace(array('[', ']'), array('%5B', '%5D'), $retval);
}

/**
 * Zobrazení nápovědy
 *
 */
function showHelp() {
    echo "Použití:\n";
    echo basename($_SERVER['argv'][0])." [-h|--help] [-v|--verbose|-vv|--more-verbose] [-V|--version] [-o|--output filename] [-a|--article] [-s|--dryrun] [-b|--begin value] [-e|--end value] [-q|--quality value] [-f|--ffmpeg] [-l|--live] url\n";
    echo "-h\tNápověda\n";
    echo "-v\tZobrazovat akce\n";
    echo "-V\tZobrazit verzi\n";
    echo "-o filename\tVýstup do souboru filename\n";
    echo "-a\turl je bráno z článku\n";
    echo "-s\tVypsat, co by se spustilo\n";
    echo "-b\tZačátek (v sekundách)\n";
    echo "-e\tKonec (v sekundách)\n";
    echo "-q\tKvalita (obvykle 288, 404, 576)\n";
    echo "-l\tPoužít parametr --live u rtmpdump\n";
    echo "-vv\tPoužít verbose i na rtmpdump\n";
    echo "-f\tPoužít ffmpeg místo vlc\n";
    echo "url\tAdresa videa (stránky s videem)\n";
}

/**
 * Parsování SMIL souboru
 *
 * @param	string	$smil	Obsah SMIL souboru
 * @return	array	Údaje ze SMIL souboru
 */
function parseSMIL($smil) {
    $retval = array(
	'video' => array()
    );
    $lines = explode("\n", $smil);
    $state = 0;
    foreach ($lines as $line) {
	$m = array();
	switch ($state) {
	case 0:
	    if (preg_match('/<switchItem/', $line)) {
		if (preg_match('/id\s*=\s*"([^"]*)"/', $line, $m)) {
		    if (strpos($m[1], 'AD') === FALSE)
			$state = 1;	// Není reklama
		}
		if (preg_match('/base\s*=\s*"([^"]*)"/', $line, $m)) {
		    $url = $m[1];
		    $url = str_replace('&amp;', '&',$url);
		    if ($state > 0)
			$retval['base'] = $url;
		}
	    } // if <switchItem
	    if (preg_match('/<PlaylistItem/', $line)) {
		if (preg_match('/id\s*=\s*"([^"]*)"/', $line, $m)) {
		    if (strpos($m[1], 'AD') === FALSE)
			$state = 2;	// Není reklama
		}
	    } // if <PlaylistItem
	    break;	// 0

	case 1:
	    if (preg_match('/<\/switchItem\s*>/', $line))
		$state = 0;
	    $url = NULL; $quality = NULL;
	    if (preg_match('/<video/', $line)) {
		if (preg_match('/src\s*=\s*"([^"]*)"/', $line, $m)) {
		    $url = $m[1];
		    $url = str_replace('&amp;', '&', $url);
		}
		if (preg_match('/label\s*=\s*"([^"]*)"/', $line, $m)) {
		    $quality = $m[1];
		}
		if (preg_match('/enabled\s*=\s*"true"/', $line)) {
		    if ($url && $quality) {
			if (!isset($retval['video']))
			    $retval['video'] = array();
			$retval['video'][$quality] = $url;
		    }
		} // if enabled
	    } // if <video
	    break;	// 1

	case 2:
	    if (preg_match('/<\/PlaylistItem\s*>/', $line))
		$state = 0;
	    if (preg_match('/<Indexes\s*>/', $line))
		$state = 3;
	    if (preg_match('/<Title\s*>([^<]*)<\/Title\s*>/', $line, $m)) {
		$retval['title'] = $m[1];
	    } // if <Title
	    break;	// 2

	case 3:
	    if (preg_match('/<\/Indexes\s*>/', $line))
		$state = 0;
	    break;	// 3
	} // switch $state
    } // foreach
    
    return $retval;
}

/**
 * Parsování dat z JSON
 *
 * Výstupní formát:
 * 'playlist' - URL s playlistem
 * 'title' - název
 *
 * @param	string	$data	JSON data
 * @return	array	Data
 */
function parseVideoJSON($data)
{
    $json = json_decode($data);
    $retval = array();
    $playList = $json->playlist;
    foreach ($playList as $item)
    {
	$streamUrls = $item->streamUrls;
	$retval['playlist'] = $streamUrls->main;
	$retval['title'] = $item->title;
    } // foreach
    return $retval;
}

/**
 * Parsování MP3 playlistu
 *
 * @param	string	$playlist	Playlist
 * @return	array	Data
 */
function parseMP3Playlist($playlist)
{
    $lines = explode("\n", $playlist);
    $retval = array();
    $q = 0;
    foreach ($lines as $line)
    {
	if (preg_match('/^#EXT-X-STREAM.*BANDWIDTH=(\d+)/', $line, $m))
	{
	    $b = $m[1];
	    if ($b < 501000)
		$q = '288p';
	    elseif ($b < 1033000)
		$q = '404p';
	    elseif ($b < 2049000)
		$q = '576p';
	    else
		$q = '1024p';
	}
	elseif (preg_match('/(http:\/\/.*m3u8)/', $line, $m))
	{
	    $retval[$q] = array(
		'type' => 'x-stream',
		'bandwidth' => $b,
		'quality' => $q,
		'url' => $m[1],
	    );
	}
    } // foreach
    return $retval;
}

/**
 * Úprava názvu souboru
 *
 * Vrací upravený název souboru tak, aby byl použitelný v shellu
 *
 * @param	string	$str
 * @return	string Upravený název
 */
function shellSanitize($str)
{
    $str = str_replace(' ', '\\ ', $str);
    $str = str_replace('"', '\\"', $str);
    $str = str_replace("'", "\\'", $str);
    return $str;
}

// Zpracování parametrů z příkazové řádky
$videoURL = NULL;
$verbose = FALSE;
$verboseMore = FALSE;
$debug = FALSE;
$outputFileName = 'video.mp4';
$prefQuality = '576';
$beginTime = NULL;
$endTime = NULL;
$isArticle = FALSE;
$dryRun = false;
$fLive = false;
$nTry = 1;
$fIsSetOutputFileName = false;
$useVLC = false;
$useFFMPEG = false;

for ($i = 1; $i < $_SERVER['argc']; $i++) {
    $val = $_SERVER['argv'][$i];
    if (substr($val, 0, 1) == '-') {
	switch (substr($val, 1)) {
	case 'h':
	case '-help':
	    showHelp();
	    exit(1);
	    break;	// 'h'

	case 'v':
	case '-verbose':
	    $verbose = TRUE;
	    break;	// 'v'

	case 'vv':
	case '-more-verbose':
	    $verbose = TRUE;
	    $verboseMore = TRUE;
	    break;	// 'vv'

	case 'V':
	case '-version':
	    echo "Verze: $VERSION\n";
	    exit (1);
	    break;	// 'V'

	case 'o':
	case '-output':
	    $i++;
	    if (isset($_SERVER['argv'][$i])) {
		$outputFileName = $_SERVER['argv'][$i];
		$fIsSetOutputFileName = true;
	    } else {
		echo "Chybí název souboru.\n";
	    }
	    break;	// 'o'

	case 'd':
	case '-debug':
	    $debug = TRUE;
	    break;	// 'd'

	case 'q':
	case '-quality':
	    $i++;
	    if (isset($_SERVER['argv'][$i])) {
		$prefQuality = intval($_SERVER['argv'][$i], 10);
	    } else {
		echo "Chybí údaj o kvalitě videa.\n";
	    }
	    break;	// 'q'

	case 'b':
	case '-begin':
	    $i++;
	    if (isset($_SERVER['argv'][$i])) {
		$beginTime = intval($_SERVER['argv'][$i], 10);
	    } else {
		echo "Chybí údaj o začátku.\n";
	    }
	    break;	// 'b'

	case 'e':
	case '-end':
	    $i++;
	    if (isset($_SERVER['argv'][$i])) {
		$endTime = intval($_SERVER['argv'][$i], 10);
	    } else {
		echo "Chybí údaj o začátku.\n";
	    }
	    break;	// 'e'

	case 'a':
	case '-article':
	    $isArticle = TRUE;
	    break;	// 'a'

	case 's':
	case '-dry':
	    $dryRun = true;
	    break;	// 's'

	case 'l':
	case '-live':
	    $fLive = true;
	    break;	// 'l'

	case 't':
	case '-try':
	    $i++;
	    if (isset($_SERVER['argv'][$i])) {
		$nTry = intval($_SERVER['argv'][$i], 10);
	    } else {
		echo "Chybí údaj o počtu opakování.\n";
	    }
	    break;	// 't'

	case 'f':
	case '-ffmpeg':
	    $useFFMPEG = true;
	    break;	// 'f'

	default:
	    echo "Neznámý přepínač $val";
	    break;	// default
	} // switch
    } else {
	// Není přepínač, jen hodnota
	$videoURL = $val;
    }
}
if ($verbose) {
    echo "$videoURL -> $outputFileName\n";
}

// Zpracování
$fLoaded = true;
for ($i = 0; $i < $nTry; $i++)
{
    if ($isArticle) {
	try {
	    $videoURL = getVideoURLFromArticle($videoURL);
	    $fLoaded = true;
	} catch (Exception $e) {
	    echo "Nepodařilo se zjistit URL videa ".($verbose ? $e->getMessage() : '').".\n";
	    $fLoaded = false;
	} // catch
    } // if
    try {
	$content = getContentFromURL($videoURL);
	$fLoaded = true;
    } catch (Exception $e) {
	echo "Chyba při stahování základní stránky ".($verbose ? $e->getMessage() : '').".\n";
	$fLoaded = false;
    } // catch
} // for
if (!$fLoaded)
{
    echo "Ani při opakovaných pokusech se nepodařilo stáhnout základní údaje.\n";
    exit(2);
}

// - získání SOAP
$method = "SOAP";
try {
    if ($debug) echo $videoURL;
    $soap = getSOAP($content);
} catch (Exception $e) {
    if ($debug)
	echo "Nepodařilo se získat SOAP data, zkusí se AJAX";
    $method = "AJAX";
}

if ($method == "SOAP")
{
    if ($debug)
	print("SOAP=$soap\n");
    // - převod na POST
    try {
	$post = SOAP2POST($soap);
    } catch (Exception $e) {
	echo "Nepodařilo se převést na POST požadavek.\n";
	exit (2);
    }
    if ($debug)
	print("POST=$post\n");
    // - zjištění URL videa
    $urls = array(
	'ajax/videoURL.php',
	'ajax/playlistURL.php',
    );
    foreach ($urls as $uIndex => $u)
    {
	try {
	    if ($debug)
		print("Získávají se data z adresy $u\n");
	    $url = getFromURL ('http://www.ceskatelevize.cz/'.$u, $post);
	    $url = preg_replace('/%26/', '&', $url);
	    if (preg_match ('/<h2>Stránka nebyla nalezena/', $url))
		throw new Exception('Stránka nebyla nalezena.');
	    if (strpos($url, '<URI>') !== false)
	    {
		$url = str_replace('<URI>', '', str_replace('</URI>', '', str_replace('hashedId', 'id', $url)));
	    }
	    break;
	} catch (Exception $e) {
	    $isLast = count($urls)-1 == $uIndex;
	    if ($isLast)
	    {
		echo "Při získávání adresy videa došlo k chybě.\n";
		exit (2);
	    }
	    if ($verbose)
		echo "Nepodařilo se získat údaje o videu z adresy '$u', zkusí se další.\n";
	}
    }
}
elseif ($method == "AJAX")
{
    $path = str_replace('http://www.ceskatelevize.cz', '', $videoURL);
    preg_match('/getPlaylistUrl.*"type":"([^"]*)","id":"([^"]*)"[^}]*}/', $content, $m);
    $post = str_replace(array('[', ']'), array('%5B', '%5D'),
	"playlist[0][type]=".$m[1]."&playlist[0][id]=".$m[2]."&requestUrl=$path&requestSource=iVysilani");
    if ($debug)
	echo "Metoda AJAX, post=$post";
    try
    {
	$obj = getFromURL('http://www.ceskatelevize.cz/ivysilani/ajax/get-client-playlist', $post);
	if ($debug)
	    echo "obj=$obj";
	if (preg_match('/"url":"([^"]*)"/', $obj, $m)) {
	    $url = str_replace('\\', '', str_replace('%26', '&', $m[1]));
	}
	else
	    throw new Exception('Nepodařilo se zjistit URL videa');
    }
    catch (Exception $e)
    {
	echo "Při získávání adresy videa došlo k chybě.\n";
	exit (2);
    }
}
else
{
    echo "Neznámá metoda $method";
    exit(2);
}
if ($verbose) {
    echo "Adresa videa: $url\n";
}
// - načtení SMIL
try {
    $smil = getFromURL($url);
} catch (Exception $e) {
    echo "Při získávání údajů o videu došlo k chybě.\n";
    exit (2);
}
if ($debug)
    print("$smil\n");
//$smil = file_get_contents('ClientPlaylist.aspx');	// DEBUG
try {
    if (preg_match('/switchItem/', $smil))
    {
    	$videos = parseSMIL($smil);
    }
    else
    {
	$mp3data = parseVideoJSON($smil);
	if ($debug)
	{
	    print("mp3data: "); print_r($mp3data);
	}
	$mp3PlayLists = getFromUrl($mp3data['playlist']);
	if ($debug)
	    print("MP3 Playlists: ".$mp3PlayLists."\n");
	$videos['title'] = $mp3data['title'];
	$videos['video'] = parseMP3Playlist($mp3PlayLists);
	$useVLC = true;
    }
} catch (Exception $e) {
    echo "Chyba při získávání údajů o videu.\n";
    exit (2);
}
// - zjištění kvality
$aQuality = array();
foreach ($videos['video'] as $key => $val) {
    $aQuality[] = intval($key, 10);
}
if (!isset($prefQuality)) {
    // Nastavení kvality
    $qlist = array(
	'576p', '404p', '288p',
    );
    foreach ($qlist as $q) {
	if (isset($videos['video'][$q])) {
	    $prefQuality = $q;
	    break;
	} // if
    } // foreach
    echo "Nepodařilo se najít video podle kvality. Použijte parametr -q pro jeho zvolení (možné hodnoty: ".implode(', ', $aQuality)."\n";
    exit (3);
}
if ($debug)
    print_r($videos);
if ($verbose) {
    if (isset($videos['title']))
	print ("Název: ".$videos['title']."\n");
    print ("Dostupná kvalita: ".implode(', ', $aQuality)."\n");
}
$prefQuality = intval($prefQuality, 10);
if (!isset($videos['video'][$prefQuality.'p'])) {
    echo "Video v požadované kvalitě (".intval($prefQuality, 10).') není k dispozici. Možné hodnoty: '.implode(', ', $aQuality)."\n";
    exit (3);
}
// Není-li zadán název videa, vytvořit z názvu
if (!$fIsSetOutputFileName)
{
    $title = isset($videos['title']) ? $videos['title'] : $videos['video'][$prefQuality.'p'];
    if (is_array($title) && isset($title['url']))
	$title = $title['url'];
    $outputFileName = $title;
    if (false !== ($p = strrpos($title, '/')))
    {
	$outputFileName = substr($title, $p+1);
    }
    $outputFileName .= ".mp4";
}

if ($useVLC)
{
    $url = $videos['video'][$prefQuality.'p']['url'];
    if ($useFFMPEG)
    {
	$ffmpegParams = array(
	    '-y',	// Override output file
	    '-i '.$url,	// Input file
	    '-c:a copy', // Audio codec: copy
	    '-c:v copy', // Video codec: copy
	    '-bsf:a aac_adtstoasc',	// Skip audio errors
	);
	if (!$verboseMore)
	    $ffmpegParams[] = '-loglevel silent';
	if (isset($videos['title']))
	    $ffmpegParams[] = '-metadata title="'.$videos['title'].'"';
	$ffmpegParams[] = shellSanitize($outputFileName);	// Output filename
	$ffmpeg = 'ffmpeg '.implode(' ', $ffmpegParams);
	if ($dryRun)
	    print($ffmpeg."\n");
	else
	    system($ffmpeg);
    }
    else
    {
	$vlcParams = '--play-and-exit --sout "#std{access=file,mux=mp4,dst='.shellSanitize($outputFileName).'}" '.$url;
	$vlc = 'cvlc '.$vlcParams;
	if ($dryRun)
	    print($vlc."\n");
	else
	    system($vlc);
    }
}
else
{
    // - sestavení parametrů pro rtmpdump
    $rtmpParams = array(
	'y' => $videos['video'][$prefQuality.'p'],
	'r' => $videos['base'],
	'o' => shellSanitize($outputFileName),
	'e' => '',
    );
    if ($fLive)
	$rtmpParams['-live'] = '';
    $pUrl = parse_url($videos['base']);
    $rtmpParams['a'] = substr($pUrl['path'], 1).'?'.$pUrl['query'];
    if ($verboseMore)
	$rtmpParams['-verbose'] = '';
    if (!$verbose && !$debug)
	$rtmpParams['q'] = '';
    if ($debug) {
	print_r($rtmpParams);
    }
    if (isset($beginTime))
	$rtmpParams['-start'] = $beginTime;
    if (isset($endTime))
	$rtmpParams['-stop'] = $endTime;
    $rtmpdump = 'rtmpdump';
    foreach ($rtmpParams as $key => $val) {
	$rtmpdump .= ' -'.$key.' '.escapeshellcmd($val);
    }
    if ($verbose) {
	print ('Spouští se: '.$rtmpdump."\n");
    }
    if ($dryRun)
    {
	print ($rtmpdump."\n");
    }
    else
    {
	system($rtmpdump);
    }
}
?>

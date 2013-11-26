<?php
/*
 * Copyright © 2013 Radosław Piliszek
 * Copyright © 2013 Dominik Tomaszuk
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software
 * and associated documentation files (the “Software”), to deal in the Software without restriction,
 * including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so,
 * subject to the following conditions:
 *
 * THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT
 * NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
 * WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
 * SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 *
 */

error_reporting(E_ALL);
require_once './lib/limonade.php';
ini_set('display_errors', 1);

/**
* \brief Konfiguracja
* \author Dominik Tomaszuk
* \date 2013-03-06
*
* Konfiguracja usługi internetowej.
*/
function configure() {
	option('env', ENV_DEVELOPMENT); /* zmienić w produkcyjnej */
	$serwisy_json = file_get_contents('serwisy_ii.json');
	option('serwisy', json_decode($serwisy_json, true));
}

/**
* \brief Wywołanie przed
* \author Dominik Tomaszuk
* \date 2013-03-06
*
* Funkcja wywołuje się przed innymi.
*/
function before($route) {
	header('Allow: GET, HEAD');
	$expires = 180; /* cache na 3 min */
	header('Pragma: public');
	header('Cache-Control: maxage='.$expires);
	header('Expires: ' . gmdate('D, d M Y H:i:s', time()+$expires) . ' GMT');
	header('Access-Control-Allow-Origin: *');
}

dispatch('/', 'index');
/**
* \brief Główna
* \author Dominik Tomaszuk
* \date 2013-03-06
*
* Funkcja przekierowuje na domyślny serwis w JSON.
*/
	function index() {
		$host = $_SERVER['HTTP_HOST'];
		$uri = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
		header("Location: http://$host$uri/?/json/io");
		exit;
	}

/**
* \brief Zwróć błąd
* \author Radosław Piliszek
* \date 2013-03-09
*
* Funkcja zwraca obiekt błędu.
*/
function blad($s) {
	$tmp = new StdClass();
	$tmp->blad = $s;
	return $tmp;
}

/**
* \brief Pobierz dane
* \author Radosław Piliszek
* \date 2013-03-09
*
* Funkcja pobiera dane serwisu w formacie JSON.
*/
function pobierz_dane($serwis, $limit, $offset) {
	$serwis = (string) $serwis;
	$limit = (int) $limit;
	$offset = (int) $offset;

	option('serwis', $serwis);
	$serwisy = option('serwisy');

	if (empty($serwis)) {
		header('HTTP/1.1 410 Gone');
		return blad('Wybierz serwis');
	}

	if (!array_key_exists($serwis, option('serwisy'))) {
		header('HTTP/1.1 404 Not Found');
		return blad('Niepoprawny serwis');
	}

	option('serwis_nazwa', $serwisy[$serwis]);

	$json = file_get_contents('http://ii.uwb.edu.pl/api/json.php?'.$serwis);

	if ($json === false) {
		header('HTTP/1.1 502 Bad Gateway');
		return blad('Brak odpowiedzi z API');
	}

	$dane = json_decode($json);

	if ($dane === null) {
		header('HTTP/1.1 502 Bad Gateway');
		return blad('Błędna odpowiedź z API');
	}

	if (abs($offset) > count($dane)) {
		header('HTTP/1.1 400 Bad Request');
		return blad('Niepoprawny offset');
	}

	if ($limit === 0) {
		$limit = null;
	}

	return array_slice($dane, $offset, $limit);
}

class MyDOMDocument extends DOMDocument {
	public function __construct() {
		parent::__construct();
		$this->formatOutput = true;
		$this->preserveWhiteSpace = false;
	}
}

/**
* \brief Konstruuj XML
* \author Radosław Piliszek
* \date 2013-03-10
*
* Funkcja przekształca pobrane dane na XML.
*/
function konstruuj_xml($dane) {
	$xml = new MyDOMDocument();

	if (is_array($dane)) {
		$root = $xml->appendChild($xml->createElement('serwis'));

		foreach ($dane as $sekcja) {
			$sekcjaXML = $root->appendChild($xml->createElement('sekcja'));
			$sekcjaXML->appendChild($xml->createElement('tytul', $sekcja->tytul));
			$tresc = $sekcjaXML->appendChild($xml->createElement('tresc'));
			$tresc->appendChild($xml->createCDATASection($sekcja->tresc));
			$sekcjaXML->appendChild($xml->createElement('data', $sekcja->data));
		}
	} else {
		$xml->appendChild($xml->createElement('blad', $dane->blad));
	}

	return $xml->saveXML();
}

/**
* \brief Konstruuj Atom
* \author Radosław Piliszek
* \date 2013-03-10
* \link http://tools.ietf.org/html/rfc4287
* \link http://tools.ietf.org/html/rfc5023
*
* Funkcja przekształca pobrane dane na Atom.
*/
function konstruuj_atom($dane) {
	$xml = new MyDOMDocument();

	if (is_array($dane)) {
		$root = $xml->appendChild($xml->createElementNS('http://www.w3.org/2005/Atom', 'feed'));
		$root->appendChild($xml->createElement('title', 'Instytut Informatyki UwB --- '.option('serwis_nazwa')));
		$link = $root->appendChild($xml->createElement('link'));
		$link->setAttribute('rel', 'self');
		$link->setAttribute('type', 'application/atom+xml');
		$link->setAttribute('href', "http://$_SERVER[SERVER_NAME]$_SERVER[REQUEST_URI]");
		$root->appendChild($xml->createElement('id', 'tag:ii.uwb.edu.pl,2013:'.option('serwis')));
		if (array_key_exists(0, $dane))
			$updated = $dane[0]->data;
		else
			$updated = '1970-01-01T00:00:00Z';
		$root->appendChild($xml->createElement('updated', $updated));
		$root->appendChild($xml->createElement('author'))
			->appendChild($xml->createElement('name', 'Instytut Informatyki'));

		foreach ($dane as $sekcja) {
			$entry = $root->appendChild($xml->createElement('entry'));
			$entry->appendChild($xml->createElement('title', $sekcja->tytul));
			$entry->appendChild($xml->createElement('updated', $sekcja->data));
			$content = $entry->appendChild($xml->createElement('content'));
			$content->setAttribute('type', 'html');
			$content->appendChild($xml->createTextNode($sekcja->tresc));
		}
	} else {
		$xml->appendChild($xml->createElement('blad', $dane->blad));
	}

	return $xml->saveXML();
}

dispatch('/xml/:serwis/:limit/:offset', 'serwuj_xml');
/**
* \brief XML
* \author Dominik Tomaszuk
* \author Radosław Piliszek
* \date 2013-03-10
*
* Funkcja serwuje XML-a.
*/
	function serwuj_xml($serwis, $limit, $offset) {
		header('Content-Type: application/xml; charset=utf-8');
		return konstruuj_xml(pobierz_dane($serwis, $limit, $offset));
	}

dispatch('/json/:serwis/:limit/:offset', 'serwuj_json');
/**
* \brief JSON
* \author Dominik Tomaszuk
* \author Radosław Piliszek
* \date 2013-03-09
*
* Funkcja serwuje JSON-a.
*/
	function serwuj_json($serwis, $limit, $offset) {
		header('Content-Type: application/json; charset=utf-8');
		return json_encode(pobierz_dane($serwis, $limit, $offset));
	}

dispatch('/atom/:serwis/:limit/:offset', 'serwuj_atom');
/**
* \brief Atom
* \author Dominik Tomaszuk
* \author Radosław Piliszek
* \date 2013-03-10
*
* Funkcja serwuje Atom-a.
*/
	function serwuj_atom($serwis, $limit, $offset) {
		header('Content-Type: application/atom+xml; charset=utf-8');
		return konstruuj_atom(pobierz_dane($serwis, $limit, $offset));
	}

run();

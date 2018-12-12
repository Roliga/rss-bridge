<?php
class BandcampBridge extends BridgeAbstract {

	const MAINTAINER = 'sebsauvage';
	const NAME = 'Bandcamp Tag';
	const URI = 'https://bandcamp.com/';
	const CACHE_TIMEOUT = 600; // 10min
	const DESCRIPTION = 'New bandcamp releases by tag or band';
	const PARAMETERS = array(
		'By tag' => array(
			'tag' => array(
				'name' => 'tag',
				'type' => 'text',
				'required' => true
			)
		),
		'By band' => array(
			'band' => array(
				'name' => 'band',
				'type' => 'text',
				'required' => true
			),
			'tracks' => array(
				'name' => 'new tracks',
				'type' => 'checkbox',
				'required' => false,
				'title' => 'New tracks added to a release shows up as new releases',
				'defaultValue' => 'checked'
			),
			'limit' => array(
				'name' => 'limit',
				'type' => 'number',
				'required' => false,
				'title' => 'Number of releases to return',
				'defaultValue' => 5
			)
		),
		'By release' => array(
			'b' => array(
				'name' => 'band',
				'type' => 'text',
				'required' => true
			),
			'release' => array(
				'name' => 'release',
				'type' => 'text',
				'required' => true
			)
		)
	);

	public function getIcon() {
		return 'https://s4.bcbits.com/img/bc_favicon.ico';
	}

	public function detectParameters($url) {
		$params = array();

		// By release
		$regex = '/^(https?:\/\/)?([^\/.&?\n]+?)\.bandcamp\.com\/album\/([^\/.&?\n]+)/';
		if(preg_match($regex, $url, $matches) > 0) {
			$params['b'] = urldecode($matches[2]);
			$params['release'] = urldecode($matches[3]);
			return $params;
		}

		// By band
		$regex = '/^(https?:\/\/)?([^\/.&?\n]+?)\.bandcamp\.com/';
		if(preg_match($regex, $url, $matches) > 0) {
			$params['band'] = urldecode($matches[2]);
			$params['tracks'] = 'on';
			return $params;
		}

		var_dump($url);
		// By tag
		$regex = '/^(https?:\/\/)?bandcamp\.com\/tag\/([^\/.&?\n]+)/';
		if(preg_match($regex, $url, $matches) > 0) {
			$params['tag'] = urldecode($matches[2]);
			return $params;
		}

		return null;
	}

	private function parseReleasePage($html) {
		foreach($html->find('script[type="text/javascript"]') as $script) {
			$regex = '/var TralbumData = {(.+?)};/s';
			if(preg_match($regex, $script->innertext, $matches) > 0) {
				$releaseDataJs = $matches[1];
				break;
			}
		}

		$releaseData = array();
		$regex = '/^ *([a-zA-Z_]+?):(.+?),?$/m';
		preg_match_all($regex, $releaseDataJs, $jsObjects, PREG_SET_ORDER);
		foreach($jsObjects as $jsObject) {
			$jsObjectName = $jsObject[1];
			$jsObjectValue = str_replace('" + "', '', $jsObject[2]);
			$releaseData[$jsObjectName] = json_decode($jsObjectValue);
		}

		$releaseData['tags'] = array();
		foreach($html->find('a.tag') as $tag) {
			$releaseData['tags'][] = $tag->plaintext;
		}

		return (object)$releaseData;
	}

	private function getSimpleHTMLDOMNewlines($url) {
		return getSimpleHTMLDOM($url,
			array(),
			array(),
			true,
			true,
			DEFAULT_TARGET_CHARSET,
			false); // Don't strip newlines, they're used when parsing release pages
	}

	/*
	 * As RSS Bridge uses the article URL as GUID, we can add a hash to
	 * the URL to present releases with new tracks as unique articles
	 */
	private function appendTrackInfoHash($url, $trackinfo) {
		$hashData = '';
		foreach($trackinfo as $track) {
			$hashData .= $track->id;
		}

		return $url
		. '#'
		. hash('md5', $hashData);
	}

	private function collectArtistData() {
		$limit = $this->getInput('limit');
		$includeNewTracks = $this->getInput('tracks');

		$html = $this->getSimpleHTMLDOMNewlines($this->getURI() . '/music');

		// Release list page, eg. https://tlrvt.bandcamp.com/music
		$releaseData = $html->find('ol.music-grid', 0);
		if(isset($releaseData)) {
			$releaseListJSON = json_decode(
				htmlspecialchars_decode(
					$releaseData->getAttribute('data-initial-values')));

			foreach($releaseListJSON as $release) {
				$item = array();

				if(isset($release->artist)) {
					$releaseArtist = $release->artist;
				} else {
					$releaseArtist = $release->band_name;
				}

				$releaseURI = $this->getURI() . $release->page_url;

				if($includeNewTracks === true) {
					$releasePageData = $this->parseReleasePage(
						$this->getSimpleHTMLDOMNewlines($releaseURI));

					$item['categories'] = $releasePageData->tags;

					$releaseURI = $this->appendTrackInfoHash($releasePageData->url,
						$releasePageData->trackinfo);
				}

				$item['uri'] = $releaseURI;

				$item['author'] = $releaseArtist;
				$item['title'] = $releaseArtist . ' - ' . $release->title;
				$item['timestamp'] = strtotime($release->publish_date);
				$item['content'] = '<img src="https://f4.bcbits.com/img/a'
				. $release->art_id
				. '_2.jpg"/><br/>'
				. $releaseArtist
				. ' - '
				. $release->title;

				$this->items[] = $item;

				if($limit > 0 && count($this->items) >= $limit) {
					break;
				}
			}
			return;
		}

		// Releases page, eg. https://parallelspec.bandcamp.com/releases
		$releasePageData = $this->parseReleasePage($html);
		if(empty($releasePageData)) {
			returnServerError('No releases found for this artist');
		} else {
			$item = array();

			if($includeNewTracks === true) {
				$item['uri'] = $this->appendTrackInfoHash($releasePageData->url,
					$releasePageData->trackinfo);
			} else {
				$item['uri'] = $releasePageData->url;
			}

			$item['author'] = $releasePageData->artist;
			$item['title'] = $releasePageData->artist
			. ' - '
			. $releasePageData->current->title;
			$item['timestamp'] = strtotime($releasePageData->current->publish_date);
			$item['categories'] = $releasePageData->tags;
			$item['content'] = '<img src="https://f4.bcbits.com/img/a'
			. $releasePageData->art_id
			. '_2.jpg"/><br/>'
			. $releasePageData->artist
			. ' - '
			. $releasePageData->current->title;
			$this->items[] = $item;
		}
	}

	private function collectReleaseData() {
		// Release/album page, eg. https://tlrvt.bandcamp.com/album/classic-waffle
		$html = $this->getSimpleHTMLDOMNewlines($this->getURI());

		$releasePageData = $this->parseReleasePage($html);

		if(empty($releasePageData)) {
			returnServerError('Could not find the specified release');
		}

		$item = array();

		$item['uri'] = $this->appendTrackInfoHash($releasePageData->url,
			$releasePageData->trackinfo);

		$item['author'] = $releasePageData->artist;
		$item['title'] = $releasePageData->artist
		. ' - '
		. $releasePageData->current->title;
		$item['timestamp'] = strtotime($releasePageData->current->publish_date);
		$item['categories'] = $releasePageData->tags;
		$item['content'] = '<img src="https://f4.bcbits.com/img/a'
		. $releasePageData->art_id
		. '_2.jpg"/><br/>'
		. $releasePageData->artist
		. ' - '
		. $releasePageData->current->title;
		$this->items[] = $item;
	}

	public function collectData(){
		switch($this->queriedContext) {
		case 'By band':
			$this->collectArtistData();
			return;
		case 'By release':
			$this->collectReleaseData();
		case 'By tag':
			$html = getSimpleHTMLDOM($this->getURI())
				or returnServerError('No results for this query.');

			foreach($html->find('li.item') as $release) {
				$script = $release->find('div.art', 0)->getAttribute('onclick');
				$uri = ltrim($script, "return 'url(");
				$uri = rtrim($uri, "')");

				$item = array();
				$item['author'] = $release->find('div.itemsubtext', 0)->plaintext
				. ' - '
				. $release->find('div.itemtext', 0)->plaintext;

				$item['title'] = $release->find('div.itemsubtext', 0)->plaintext
				. ' - '
				. $release->find('div.itemtext', 0)->plaintext;

				$item['content'] = '<img src="'
				. $uri
				. '"/><br/>'
				. $release->find('div.itemsubtext', 0)->plaintext
				. ' - '
				. $release->find('div.itemtext', 0)->plaintext;

				$item['id'] = $release->find('a', 0)->getAttribute('href');
				$item['uri'] = $release->find('a', 0)->getAttribute('href');
				$this->items[] = $item;
			}
		}
	}

	public function getURI(){
		switch($this->queriedContext) {
		case 'By band':
			if(!is_null($this->getInput('band'))) {
				return 'https://' . $this->getInput('band') . '.bandcamp.com';
			}
		case 'By release':
			if(!is_null($this->getInput('b'))
			&& !is_null($this->getInput('release'))) {
				return 'https://'
				. $this->getInput('b')
				. '.bandcamp.com/album/'
				. $this->getInput('release');
			}
		case 'By tag':
			if(!is_null($this->getInput('tag'))) {
				return self::URI
				. 'tag/'
				. urlencode($this->getInput('tag'))
				. '?sort_field=date';
			}
		default: return parent::getURI();
		}
	}

	public function getName(){
		switch($this->queriedContext) {
		case 'By band':
			if(!is_null($this->getInput('band'))) {
				return $this->getInput('band') . ' - Bandcamp Artist';
			}
		case 'By release':
			if(!is_null($this->getInput('b'))
			&& !is_null($this->getInput('release'))) {
				return $this->getInput('b')
				. ' - '
				. $this->getInput('release')
				. ' - Bandcamp Release';
			}
		case 'By tag':
			if(!is_null($this->getInput('tag'))) {
				return $this->getInput('tag') . ' - Bandcamp Tag';
			}
		default: return parent::getName();
		}
	}
}

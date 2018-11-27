<?php
class BandcampBridge extends BridgeAbstract {

	const MAINTAINER = 'sebsauvage';
	const NAME = 'Bandcamp Tag';
	const URI = 'https://bandcamp.com/';
	const CACHE_TIMEOUT = 600; // 10min
	const DESCRIPTION = 'New bandcamp release by tag';
	const PARAMETERS = array(
		'By tag' => array(
			'tag' => array(
				'name' => 'tag',
				'type' => 'text',
				'required' => true
			)
		),
		'Band releases' => array(
			'band' => array(
				'name' => 'band',
				'type' => 'text',
				'required' => true
			)
		)
	);

	public function getIcon() {
		return 'https://s4.bcbits.com/img/bc_favicon.ico';
	}

	private function parseAlbumPage($html){
		foreach($html->find('script[type="text/javascript"]') as $script) {
			$regex = '/var TralbumData = {(.+?)};/s';
			if(preg_match($regex, $script->innertext, $matches) > 0) {
				$albumDataJs = $matches[1];
				break;
			}
		}

		$albumData = array();
		$regex = '/^ *([a-zA-Z_]+?):(.+?),?$/m';
		preg_match_all($regex, $albumDataJs, $jsObjects, PREG_SET_ORDER);
		foreach($jsObjects as $jsObject) {
			$albumData[$jsObject[1]] = json_decode($jsObject[2]);
		}

		return (object)$albumData;
	}

	private function collectArtistData(){
		$html = getSimpleHTMLDOM($this->getURI() . '/music',
			array(),
			array(),
			true,
			true,
			DEFAULT_TARGET_CHARSET,
			false); // Don't strip newlines, they're used when parsing album pages

		// Album list page, eg. https://tlrvt.bandcamp.com/music
		$albumData = $html->find('ol.music-grid', 0);
		if(isset($albumData)) {
			$albumListJSON = json_decode(htmlspecialchars_decode($albumData->getAttribute('data-initial-values')));
			foreach($albumListJSON as $album) {
				if(isset($album->artist)) {
					$albumArtist = $album->artist;
				} else {
					$albumArtist = $album->band_name;
				}
				$albumURI = $this->getURI() . $album->page_url;

				$item = array();
				$item['uri'] = $albumURI;

				$item['author'] = $albumArtist;
				$item['title'] = $albumArtist . ' - ' . $album->title;
				$item['timestamp'] = strtotime($album->publish_date);
				$item['content'] = '<img src="https://f4.bcbits.com/img/a'
				. $album->art_id
				. '_2.jpg"/><br/>'
				. $albumArtist
				. ' - '
				. $album->title;
				$this->items[] = $item;
			}
		}

		// Releases page, eg. https://parallelspec.bandcamp.com/releases
		$albumData = $this->parseAlbumPage($html);
		if(empty($albumData)) {
			returnServerError('No albums found for this artist');
		} else {
			$item = array();
			$item['uri'] = $albumData->url;

			$item['author'] = $albumData->artist;
			$item['title'] = $albumData->artist . ' - ' . $albumData->current->title;
			$item['timestamp'] = strtotime($albumData->current->publish_date);
			$item['content'] = '<img src="https://f4.bcbits.com/img/a'
			. $albumData->art_id
			. '_2.jpg"/><br/>'
			. $albumData->artist
			. ' - '
			. $albumData->current->title;
			$this->items[] = $item;
		}
	}

	public function collectData(){
		if($this->queriedContext === 'Band releases') {
			$this->collectArtistData();
			return;
		}

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

	public function getURI(){
		switch($this->queriedContext) {
		case 'Band releases':
			if(!is_null($this->getInput('band'))) {
				return 'https://' . $this->getInput('band') . '.bandcamp.com';
			}
		case 'By tag':
			if(!is_null($this->getInput('tag'))) {
				return self::URI . 'tag/' . urlencode($this->getInput('tag')) . '?sort_field=date';
			}
		default: return parent::getURI();
		}
	}

	public function getName(){
		if(!is_null($this->getInput('tag'))) {
			return $this->getInput('tag') . ' - Bandcamp Tag';
		}

		return parent::getName();
	}
}

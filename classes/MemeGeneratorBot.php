<?php

require_once(__DIR__.'/Bot.php');

/**
* Meme Generator Bot class
*/
class MemeGeneratorBot extends Bot {
	protected function about($aJson) {
		return $this->sendMessage($this->getChatId($aJson), "This bot gives you the ability to create Memes on the fly.\n\nDeveloped by @Nappa85 under GPLv4\nSource code: https://github.com/nappa85/Telegram");
	}

	protected function help($aJson) {
		return $this->sendMessage($this->getChatId($aJson), "/listMemes - List avaiable Memes\nYou can specify the length of the list, default is 10, or a string to filter by.\nFor example:\n/listMemes 50\n/listMemes batman\n\n/newMeme - Generate a new Meme\nYou will be asked for an ID or a search string for the Meme, then for the first and the second texts.\nIf a search string is provided, the first matching Meme will be used.\nIf you need to leave empty one of the two strings, use the \"-\" character.\n\n/suggest - Suggest an improvement to the developer\nYou can pass an inline argument, or call the command and insert the subject when asked.\nFor example:\n/suggest How can I add a new Meme image to the list?");
	}

	protected function _getList() {
		return json_decode(file_get_contents('https://api.imgflip.com/get_memes'), true);
	}

	protected function _getTemplateId($sSearch) {
		//check if it's already a valid id
		if(is_numeric($sSearch)) {
			$rCurl = curl_init();
			curl_setopt_array($rCurl, array(
				CURLOPT_URL => 'https://imgflip.com/memegenerator/'.$sSearch,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64; rv:47.0) Gecko/20100101 Firefox/47.0',
				CURLOPT_REFERER => 'https://imgflip.com/memegenerator',
				CURLOPT_HEADER => true,
			));
			$sResponse = curl_exec($rCurl);
			curl_close($rCurl);

			if(!preg_match('/location\: \/memegenerator/', $sResponse)) {
				return $sSearch;
			}
		}
		else {
			$aMemes = $this->_getList();

			//doesn't affects numeric strings
			$sSearch = preg_replace('/\W+/', '', strtolower($sSearch));

			foreach($aMemes['data']['memes'] as $aMeme) {
				if(($aMeme['id'] == $sSearch) || (!empty($sSearch) && (strpos(preg_replace('/\W+/', '', strtolower($aMeme['name'])), $sSearch) !== false))) {
					return $aMeme['id'];
				}
			}
		}

		return false;
	}

	protected function _uploadImage(&$aJson) {
		if(is_array($aJson['message']['photo'])) {
			//scan various formats to select the best one, max 20MB
			$iBestIndex = 0;
			foreach($aJson['message']['photo'] as $iIndex => $aPhoto) {
				if(($aJson['message']['photo'][$iBestIndex]['file_size'] < $aPhoto['file_size']) && ($aPhoto['file_size'] < 20971520)) {
					$iBestIndex = $iIndex;
				}
			}

			$sTempFile = $this->getFile($aJson['message']['photo'][$iBestIndex]['file_id']);
			if($sTempFile === false) {
				return false;
			}

			$rCurl = curl_init();
			curl_setopt_array($rCurl, array(
				CURLOPT_URL => 'https://imgflip.com/memeAdd',
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_POST => 1,
				CURLOPT_POSTFIELDS => array(
					'memeFile' => new CurlFile($sTempFile),
					'customName' => '',
				),
				CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64; rv:47.0) Gecko/20100101 Firefox/47.0',
				CURLOPT_REFERER => 'https://imgflip.com/memegenerator',
				CURLOPT_HEADER => true,
			));
			$sResponse = curl_exec($rCurl);
			curl_close($rCurl);

			if(preg_match('/location\: \/memegenerator\/(\d+)/', $sResponse, $aMatch)) {
				//replace message text to replace future args element
				$aJson['message']['text'] = $aMatch[1];
				return $aMatch[1];
			}
		}

		return false;
	}

	/**
	* List avaiable Memes
	* @param   $json   array   the user message
	*/
	protected function listMemes($aJson, $iLimit = 10) {
		$aMemes = $this->_getList();

		if(is_numeric($iLimit)) {
			$sSearch = null;
			$iLimit = (int)$iLimit;
		}
		else {
			$sSearch = preg_replace('/\W+/', '', strtolower($iLimit));
			$iLimit = 10;
		}

		$aOut = array();
		$iCount = 0;
		foreach($aMemes['data']['memes'] as $aMeme) {
			if($iCount >= $iLimit) {
				break;
			}

			if(empty($sSearch) || (strpos(preg_replace('/\W+/', '', strtolower($aMeme['name'])), $sSearch) !== false)) {
				$aOut[] = $aMeme['name'].' ('.$aMeme['id'].') '.$aMeme['url'];
				$iCount++;
			}
		}

		return $this->sendMessage($this->getChatId($aJson), empty($aOut)?'No Memes found':implode("\n", $aOut), null, false, false);
	}

	/**
	* Generate a new Meme
	* @param   $json   array   the user message
	*/
	protected function newMeme($aJson) {
		$aParams = $this->aConfig['params'];

		$aArgs = func_get_args();
		$iCount = count($aArgs);
		if($iCount > 1) {
			//scan last 3 parameters
			for($i = ($iCount > 3?$iCount - 3:1); $i < $iCount; $i++) {
				if(empty($aParams['template_id']) && (($iTemplateId = $this->_uploadImage($aJson)) || ($iTemplateId = $this->_getTemplateId($aArgs[$i])))) {
					$aParams['template_id'] = $iTemplateId;
				}
				elseif(!empty($aParams['template_id']) && empty($aParams['text0'])) {
					$aParams['text0'] = $aArgs[$i];
				}
				elseif(!empty($aParams['template_id']) && !empty($aParams['text0']) && empty($aParams['text1'])) {
					$aParams['text1'] = $aArgs[$i];
				}
			}
		}

		if(empty($aParams['template_id'])) {
			$this->storeMessage($aJson);
			return $this->storeMessage($this->sendMessage($this->getChatId($aJson), "Insert a meme name or ID\nYou can use /listMemes to retrieve a list of avaiable Memes\nThe list comes in \"name (ID) link\" format.\nIf you can't find your meme, you can upload an image instead.", $this->getMessageId($aJson), true));
		}
		elseif(empty($aParams['text0'])) {
			$this->storeMessage($aJson);
			return $this->storeMessage($this->sendMessage($this->getChatId($aJson), "Insert the top text for the Image (use \"-\" for empty string)", $this->getMessageId($aJson), true));
		}
		elseif(empty($aParams['text1'])) {
			$this->storeMessage($aJson);
			return $this->storeMessage($this->sendMessage($this->getChatId($aJson), "Insert the bottom text for the Image (use \"-\" for empty string)", $this->getMessageId($aJson), true));
		}

		if($aParams['text0'] == '-') {
			$aParams['text0'] = '';
		}
		if($aParams['text1'] == '-') {
			$aParams['text1'] = '';
		}

		$rCurl = curl_init();
		curl_setopt_array($rCurl, array(
			CURLOPT_URL => 'https://api.imgflip.com/caption_image',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => $aParams
		));
		$sResponse = curl_exec($rCurl);
		curl_close($rCurl);

		$aResponse = json_decode($sResponse, true);
		if($aResponse['success']) {
			$this->sendChatAction($this->getChatId($aJson), 'upload_photo');

			$this->sendPhoto($this->getChatId($aJson), $aResponse['data']['url'], $aResponse['data']['page_url']);
		}
		else {
			$this->sendMessage($this->getChatId($aJson), empty($aResponse['error_message'])?$aResponse['description']:$aResponse['error_message']);
		}

		return $this->recursivelyDeleteStoredMessage($this->getChatId($aJson), $this->getReplyToMessageId($aJson));
	}
}

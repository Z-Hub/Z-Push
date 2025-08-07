<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2022 grommunio GmbH
 *
 * Provides the FIND command
 */

class Find extends RequestProcessor {

	/**
	 * Handles the Search command
	 *
	 * @param int       $commandCode
	 *
	 * @access public
	 * @return boolean
	 */
	public function Handle($commandCode) {

		$searchrange = '0';

		$cpo = new ContentParameters();

		if(!self::$decoder->getElementStartTag(SYNC_FIND_FIND)) {
			ZLog::Write(LOGLEVEL_DEBUG, "ERROR: No find tag");
			return false;
		}

		if(!self::$decoder->getElementStartTag(SYNC_FIND_SEARCHID))
			return false;
		$searchId = self::$decoder->getElementContent();
		$cpo->SetFindSearchId($searchId);

		ZLog::Write(LOGLEVEL_DEBUG, "SearchId: $searchId");
		if(!self::$decoder->getElementEndTag())
			return false;

		if(!self::$decoder->getElementStartTag(SYNC_FIND_EXECUTESEARCH))
			return false;

		if(!self::$decoder->getElementStartTag(SYNC_FIND_MAILBOXSEARCHCRITERION))
			return false;

		if(!self::$decoder->getElementStartTag(SYNC_FIND_QUERY))
			return false;

		if (self::$decoder->getElementStartTag(SYNC_FOLDERTYPE)) {
			$searchclass = self::$decoder->getElementContent();

			ZLog::Write(LOGLEVEL_DEBUG, "FolderType: $searchclass");
			$cpo->SetSearchClass($searchclass);
			if(!self::$decoder->getElementEndTag()) {// SYNC_FOLDERTYPE
				ZLog::Write(LOGLEVEL_DEBUG, "ERROR: No end tag");
				return false;
			}
		}

		if (self::$decoder->getElementStartTag(SYNC_FOLDERID)) {
			$searchfolderid = self::$decoder->getElementContent();
			ZLog::Write(LOGLEVEL_DEBUG, "FolderId: $searchfolderid");
			$cpo->SetSearchFolderid($searchfolderid);
			if(!self::$decoder->getElementEndTag()) {// SYNC_FOLDERTYPE
				ZLog::Write(LOGLEVEL_DEBUG, "ERROR: No end tag");
				return false;
			}
		}

		if (self::$decoder->getElementStartTag(SYNC_FIND_FREETEXT)) {
			$searchfreetext = self::$decoder->getElementContent();

			ZLog::Write(LOGLEVEL_DEBUG, "FreeText: $searchfreetext");
			$cpo->SetFindFreeText($searchfreetext);
			if(!self::$decoder->getElementEndTag()) {// SYNC_FOLDERTYPE
				ZLog::Write(LOGLEVEL_DEBUG, "ERROR: No end tag");
				return false;
			}
		}

		if(!self::$decoder->getElementEndTag()) // SYNC_SEARCH_QUERY
			return false;


		if(self::$decoder->getElementStartTag(SYNC_FIND_OPTIONS)) {

			if (self::$decoder->getElementStartTag(SYNC_FIND_RANGE)) {
				$searchrange = self::$decoder->getElementContent();
				ZLog::Write(LOGLEVEL_DEBUG, "Range: $searchrange");
				$cpo->SetFindRange($searchrange);
				if (!self::$decoder->getElementEndTag())
					return false;
			}

			if (self::$decoder->getElementStartTag(SYNC_FIND_DEEPTRAVERSAL)) {
				$deeptraversal = true;
				if (($dam = self::$decoder->getElementContent()) !== false) {
					$deeptraversal = true;
					if (!self::$decoder->getElementEndTag()) {
						return false;
					}
				}
				$cpo->SetFindDeepTraversal($deeptraversal);
			}
			if (!self::$decoder->getElementEndTag())
				return false;
		}

		if(!self::$decoder->getElementEndTag()) // MailBoxSearchCriterion
			return false;

		if(!self::$decoder->getElementEndTag()) // ExecuteSearch
			return false;

		if(!self::$decoder->getElementEndTag()) //find
			return false;

		ZLog::Write(LOGLEVEL_DEBUG, var_export($cpo->GetDataArray(), true));




		$searchprovider = ZPush::GetSearchProvider();
		$status = SYNC_SEARCHSTATUS_SUCCESS;
		$searchtotal = 0;
		$rows = array();

		// TODO support other searches

		switch($searchclass) {
			case "Email":
				try {
					if ($searchprovider->SupportsType(ISearchProvider::SEARCH_MAILBOX)) {
						$backendFolderId = self::$deviceManager->GetBackendIdForFolderId($cpo->GetSearchFolderid());
						$cpo->SetFindFolderid($backendFolderId);
						$rows = $searchprovider->GetMailboxSearchResults($cpo);
					} else {
						$rows = array('searchtotal' => 0);
						$status = SYNC_SEARCHSTATUS_SERVERERROR;
						ZLog::Write(LOGLEVEL_WARN, sprintf("Searchtype '%s' is not supported.", $searchclass));
						self::$topCollector->AnnounceInformation(sprintf("Unsupported type '%s''", $searchclass), true);
					}
				} catch(StatusException $stex) {
					$storestatus = $stex->getCode();
				}
				break;

			case "GAL":
				//TODO
//			    $rows = $searchprovider->GetGALSearchResults($searchquery, $searchrange, $searchpicture);
				break;
		}

		if (isset($rows['range'])) {
			$searchrange = $rows['range'];
			unset($rows['range']);
		}
		if (isset($rows['searchtotal'])) {
			$searchtotal = $rows['searchtotal'];
			unset($rows['searchtotal']);
		}


		self::$encoder->startWBXML();
		self::$encoder->startTag(SYNC_FIND_FIND);

		self::$encoder->startTag(SYNC_FIND_STATUS);
		self::$encoder->content($status);
		self::$encoder->endTag();

		self::$encoder->startTag(SYNC_FIND_RESPONSE);

		self::$encoder->startTag(SYNC_ITEMOPERATIONS_STORE);
		self::$encoder->content("Mailbox");
		self::$encoder->endTag(); // Store

		self::$encoder->startTag(SYNC_FIND_STATUS);
		self::$encoder->content($status);
		self::$encoder->endTag();



		foreach ($rows as $u) {
			list($longfolderid, $uid) = Utils::SplitMessageId($u['longid']);
			$folderid = self::$deviceManager->GetFolderIdForBackendId($u['folderid']);
			$message = self::$backend->Fetch($folderid, $uid, $cpo);

			/** @var SyncMail $message */

			self::$encoder->startTag(SYNC_FIND_RESULT);
			self::$encoder->startTag("FolderType"); //Class
			self::$encoder->content($u['class']);
			self::$encoder->endTag();
			self::$encoder->startTag(SYNC_SERVERENTRYID); //ServerId
			self::$encoder->content($uid);
			self::$encoder->endTag();
			self::$encoder->startTag(SYNC_FOLDERID); //CollectionId
			self::$encoder->content($folderid);
			self::$encoder->endTag();

			self::$encoder->startTag(SYNC_FIND_PROPERTIES);

			$fpmessage = SyncFindProperties::GetObjectFromSyncMail($message);
			$fpmessage->Encode(self::$encoder);

			self::$encoder->endTag();//properties

			self::$encoder->endTag();//result
		}

		self::$encoder->startTag(SYNC_FIND_RANGE);
		self::$encoder->content($searchrange);
		self::$encoder->endTag();

		self::$encoder->startTag(SYNC_FIND_TOTAL);
		self::$encoder->content($searchtotal);
		self::$encoder->endTag();

		self::$encoder->endTag(); // Response

		self::$encoder->endTag(); //Find

		return true;
	}
}

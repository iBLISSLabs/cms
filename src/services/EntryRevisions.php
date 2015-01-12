<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\services;

use Craft;
use craft\app\enums\SectionType;
use craft\app\errors\Exception;
use craft\app\events\DraftEvent;
use craft\app\events\EntryEvent;
use craft\app\helpers\JsonHelper;
use craft\app\models\Entry as EntryModel;
use craft\app\models\EntryDraft as EntryDraftModel;
use craft\app\models\EntryVersion as EntryVersionModel;
use craft\app\records\EntryDraft as EntryDraftRecord;
use craft\app\records\EntryVersion as EntryVersionRecord;
use yii\base\Component;

Craft::$app->requireEdition(Craft::Client);

/**
 * Class EntryRevisions service.
 *
 * An instance of the EntryRevisions service is globally accessible in Craft via [[Application::entryRevisions `Craft::$app->entryRevisions`]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class EntryRevisions extends Component
{
	// Constants
	// =========================================================================

	/**
     * @event Event The event that is triggered after a draft is saved.
     */
    const EVENT_AFTER_SAVE_DRAFT = 'afterSaveDraft';

	/**
     * @event Event The event that is triggered after a draft is published.
     */
    const EVENT_AFTER_PUBLISH_DRAFT = 'afterPublishDraft';

	/**
     * @event Event The event that is triggered before a draft is deleted.
     */
    const EVENT_BEFORE_DELETE_DRAFT = 'beforeDeleteDraft';

	/**
     * @event Event The event that is triggered after a draft is deleted.
     */
    const EVENT_AFTER_DELETE_DRAFT = 'afterDeleteDraft';

	/**
     * @event Event The event that is triggered after an entry is reverted to an old version.
     */
    const EVENT_AFTER_REVERT_ENTRY_TO_VERSION = 'afterRevertEntryToVersion';

	// Public Methods
	// =========================================================================

	/**
	 * Returns a draft by its ID.
	 *
	 * @param int $draftId
	 *
	 * @return EntryDraftModel|null
	 */
	public function getDraftById($draftId)
	{
		$draftRecord = EntryDraftRecord::model()->findById($draftId);

		if ($draftRecord)
		{
			$draft = EntryDraftModel::populateModel($draftRecord);

			// This is a little hacky, but fixes a bug where entries are getting the wrong URL when a draft is published
			// inside of a structured section since the selected URL Format depends on the entry's level, and there's no
			// reason to store the level along with the other draft data.
			$entry = Craft::$app->entries->getEntryById($draftRecord->entryId, $draftRecord->locale);

			$draft->root  = $entry->root;
			$draft->lft   = $entry->lft;
			$draft->rgt   = $entry->rgt;
			$draft->level = $entry->level;

			return $draft;
		}
	}

	/**
	 * Returns drafts of a given entry.
	 *
	 * @param int    $entryId
	 * @param string $localeId
	 *
	 * @return array
	 */
	public function getDraftsByEntryId($entryId, $localeId = null)
	{
		if (!$localeId)
		{
			$localeId = Craft::$app->i18n->getPrimarySiteLocale();
		}

		$drafts = [];

		$results = Craft::$app->db->createCommand()
			->select('*')
			->from('entrydrafts')
			->where(['and', 'entryId = :entryId', 'locale = :locale'], [':entryId' => $entryId, ':locale' => $localeId])
			->order('name asc')
			->queryAll();

		foreach ($results as $result)
		{
			$result['data'] = JsonHelper::decode($result['data']);

			// Don't initialize the content
			unset($result['data']['fields']);

			$drafts[] = EntryDraftModel::populateModel($result);
		}

		return $drafts;
	}

	/**
	 * Returns the drafts of a given entry that are editable by the current user.
	 *
	 * @param int    $entryId
	 * @param string $localeId
	 *
	 * @return array
	 */
	public function getEditableDraftsByEntryId($entryId, $localeId = null)
	{
		$editableDrafts = [];
		$user = Craft::$app->getUser()->getIdentity();

		if ($user)
		{
			$allDrafts = $this->getDraftsByEntryId($entryId, $localeId);

			foreach ($allDrafts as $draft)
			{
				if ($draft->creatorId == $user->id || $user->can('editPeerEntryDrafts:'.$draft->sectionId))
				{
					$editableDrafts[] = $draft;
				}
			}
		}

		return $editableDrafts;
	}

	/**
	 * Saves a draft.
	 *
	 * @param EntryDraftModel $draft
	 *
	 * @return bool
	 */
	public function saveDraft(EntryDraftModel $draft)
	{
		$draftRecord = $this->_getDraftRecord($draft);

		if (!$draft->name && $draft->id)
		{
			// Get the total number of existing drafts for this entry/locale
			$totalDrafts = Craft::$app->db->createCommand()
				->from('entrydrafts')
				->where(
					['and', 'entryId = :entryId', 'locale = :locale'],
					[':entryId' => $draft->id, ':locale' => $draft->locale]
				)
				->count('id');

			$draft->name = Craft::t('Draft {num}', ['num' => $totalDrafts + 1]);
		}

		$draftRecord->name = $draft->name;
		$draftRecord->notes = $draft->revisionNotes;
		$draftRecord->data = $this->_getRevisionData($draft);

		$isNewDraft = !$draft->draftId;

		if ($draftRecord->save())
		{
			$draft->draftId = $draftRecord->id;

			// Fire an 'afterSaveDraft' event
			$this->trigger(static::EVENT_AFTER_SAVE_DRAFT, new DraftEvent([
				'draft' => $draft
			]));

			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Publishes a draft.
	 *
	 * @param EntryDraftModel $draft
	 *
	 * @return bool
	 */
	public function publishDraft(EntryDraftModel $draft)
	{
		// If this is a single, we'll have to set the title manually
		if ($draft->getSection()->type == SectionType::Single)
		{
			$draft->getContent()->title = $draft->getSection()->name;
		}

		// Set the version notes
		if (!$draft->revisionNotes)
		{
			$draft->revisionNotes = Craft::t('Published draft “{name}”.', ['name' => $draft->name]);
		}

		if (Craft::$app->entries->saveEntry($draft))
		{
			// Fire an 'afterPublishDraft' event
			$this->trigger(static::EVENT_AFTER_PUBLISH_DRAFT, new DraftEvent([
				'draft' => $draft
			]));

			$this->deleteDraft($draft);
			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Deletes a draft by it's model.
	 * @param EntryDraftModel $draft
	 */
	public function deleteDraft(EntryDraftModel $draft)
	{
		$transaction = Craft::$app->db->getCurrentTransaction() === null ? Craft::$app->db->beginTransaction() : null;

		try
		{
			// Fire a 'beforeDeleteDraft' event
			$event = new DraftEvent([
				'draft' => $draft
			]);

			$this->trigger(static::EVENT_BEFORE_DELETE_DRAFT, $event);

			// Is the event giving us the go-ahead?
			if ($event->performAction)
			{
				$draftRecord = $this->_getDraftRecord($draft);
				$draftRecord->delete();

				$success = true;
			}
			else
			{
				$success = false;
			}

			// Commit the transaction regardless of whether we deleted the draft, in case something changed
			// in onBeforeDeleteDraft
			if ($transaction !== null)
			{
				$transaction->commit();
			}
		}
		catch (\Exception $e)
		{
			if ($transaction !== null)
			{
				$transaction->rollback();
			}

			throw $e;
		}

		if ($success)
		{
			// Fire an 'afterDeleteDraft' event
			$this->trigger(static::EVENT_AFTER_DELETE_DRAFT, new DraftEvent([
				'draft' => $draft
			]));
		}

		return $success;
	}

	/**
	 * Returns a version by its ID.
	 *
	 * @param int $versionId
	 *
	 * @return EntryDraftModel|null
	 */
	public function getVersionById($versionId)
	{
		$versionRecord = EntryVersionRecord::model()->findById($versionId);

		if ($versionRecord)
		{
			return EntryVersionModel::populateModel($versionRecord);
		}
	}

	/**
	 * Returns versions by an entry ID.
	 *
	 * @param int      $entryId
	 * @param string   $localeId
	 * @param int|null $limit
	 *
	 * @return array
	 */
	public function getVersionsByEntryId($entryId, $localeId, $limit = null)
	{
		if (!$localeId)
		{
			$localeId = Craft::$app->i18n->getPrimarySiteLocale();
		}

		$versions = [];

		$results = Craft::$app->db->createCommand()
			->select('*')
			->from('entryversions')
			->where(['and', 'entryId = :entryId', 'locale = :locale'], [':entryId' => $entryId, ':locale' => $localeId])
			->order('dateCreated desc')
			->offset(1)
			->limit($limit)
			->queryAll();

		foreach ($results as $result)
		{
			$result['data'] = JsonHelper::decode($result['data']);

			// Don't initialize the content
			unset($result['data']['fields']);

			$versions[] = EntryVersionModel::populateModel($result);
		}

		return $versions;
	}

	/**
	 * Saves a new version.
	 *
	 * @param EntryModel $entry
	 *
	 * @return bool
	 */
	public function saveVersion(EntryModel $entry)
	{
		// Get the total number of existing versions for this entry/locale
		$totalVersions = Craft::$app->db->createCommand()
			->from('entryversions')
			->where(
				['and', 'entryId = :entryId', 'locale = :locale'],
				[':entryId' => $entry->id, ':locale' => $entry->locale]
			)
			->count('id');

		$versionRecord = new EntryVersionRecord();
		$versionRecord->entryId = $entry->id;
		$versionRecord->sectionId = $entry->sectionId;
		$versionRecord->creatorId = Craft::$app->getUser()->getIdentity() ? Craft::$app->getUser()->getIdentity()->id : $entry->authorId;
		$versionRecord->locale = $entry->locale;
		$versionRecord->num = $totalVersions + 1;
		$versionRecord->data = $this->_getRevisionData($entry);
		$versionRecord->notes = $entry->revisionNotes;

		return $versionRecord->save();
	}

	/**
	 * Reverts an entry to a version.
	 *
	 * @param EntryVersionModel $version
	 *
	 * @return bool
	 */
	public function revertEntryToVersion(EntryVersionModel $version)
	{
		// If this is a single, we'll have to set the title manually
		if ($version->getSection()->type == SectionType::Single)
		{
			$version->getContent()->title = $version->getSection()->name;
		}

		// Set the version notes
		$version->revisionNotes = Craft::t('Reverted version {num}.', ['num' => $version->num]);

		if (Craft::$app->entries->saveEntry($version))
		{
			// Fire an 'afterRevertEntryToVersion' event
			$this->trigger(static::EVENT_AFTER_REVERT_ENTRY_TO_VERSION, new EntryEvent([
				'entry' => $version,
			]));

			return true;
		}
		else
		{
			return false;
		}
	}

	// Private Methods
	// =========================================================================

	/**
	 * Returns a draft record.
	 *
	 * @param EntryDraftModel $draft
	 *
	 * @throws Exception
	 * @return EntryDraftRecord
	 */
	private function _getDraftRecord(EntryDraftModel $draft)
	{
		if ($draft->draftId)
		{
			$draftRecord = EntryDraftRecord::model()->findById($draft->draftId);

			if (!$draftRecord)
			{
				throw new Exception(Craft::t('No draft exists with the ID “{id}”.', ['id' => $draft->draftId]));
			}
		}
		else
		{
			$draftRecord = new EntryDraftRecord();
			$draftRecord->entryId   = $draft->id;
			$draftRecord->sectionId = $draft->sectionId;
			$draftRecord->creatorId = $draft->creatorId;
			$draftRecord->locale    = $draft->locale;
		}

		return $draftRecord;
	}

	/**
	 * Returns an array of all the revision data for a draft or version.
	 *
	 * @param EntryDraftModel|EntryVersionModel $revision
	 *
	 * @return array
	 */
	private function _getRevisionData($revision)
	{
		$revisionData = [
			'typeId'     => $revision->typeId,
			'authorId'   => $revision->authorId,
			'title'      => $revision->title,
			'slug'       => $revision->slug,
			'postDate'   => ($revision->postDate   ? $revision->postDate->getTimestamp()   : null),
			'expiryDate' => ($revision->expiryDate ? $revision->expiryDate->getTimestamp() : null),
			'enabled'    => $revision->enabled,
			'fields'     => [],
		];

		$content = $revision->getContentFromPost();

		foreach (Craft::$app->fields->getAllFields() as $field)
		{
			if (isset($content[$field->handle]) && $content[$field->handle] !== null)
			{
				$revisionData['fields'][$field->id] = $content[$field->handle];
			}
		}

		return $revisionData;
	}
}

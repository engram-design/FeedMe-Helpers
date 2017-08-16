<?php
namespace Craft;

use Cake\Utility\Hash as Hash;

class Comments_CommentFeedMeElementType extends BaseFeedMeElementType
{
    // Templates
    // =========================================================================

    public function getGroupsTemplate()
    {
        return 'commentshelper/_integrations/feedme/elementtypes/groups';
    }

    public function getColumnTemplate()
    {
        return 'commentshelper/_integrations/feedme/elementtypes/column';
    }

    public function getMappingTemplate()
    {
        return 'commentshelper/_integrations/feedme/elementtypes/map';
    }


    // Public Methods
    // =========================================================================

    public function getGroups()
    {
        return array();
    }

    public function setModel($settings)
    {
        $element = new Comments_CommentModel();

        if ($settings['locale']) {
            $element->locale = $settings['locale'];
        }

        return $element;
    }

    public function setCriteria($settings)
    {
        $criteria = craft()->elements->getCriteria('Comments_Comment');
        $criteria->status = null;
        $criteria->limit = null;
        $criteria->localeEnabled = null;

        if ($settings['locale']) {
            $criteria->locale = $settings['locale'];
        }

        return $criteria;
    }

    public function matchExistingElement(&$criteria, $data, $settings)
    {
        foreach ($settings['fieldUnique'] as $handle => $value) {
            if ((int)$value === 1) {
                $feedValue = Hash::get($data, $handle . '.data', $data[$handle]);

                if ($feedValue) {
                    $criteria->$handle = DbHelper::escapeParam($feedValue);
                }
            }
        }

        // Check to see if an element already exists - interestingly, find()[0] is faster than first()
        $elements = $criteria->find();

        if (count($elements)) {
            return $elements[0];
        }

        return null;
    }

    public function delete(array $elements)
    {
        craft()->comments->deleteComment($elements);
    }

    public function prepForElementModel(BaseElementModel $element, array &$data, $settings)
    {
        foreach ($data as $handle => $value) {
            if (is_null($value)) {
                continue;
            }

            if (isset($value['data']) && $value['data'] === null) {
                continue;
            }

            if (is_array($value)) {
                $dataValue = Hash::get($value, 'data', $value);
            } else {
                $dataValue = $value;
            }

            switch ($handle) {
                case 'structureId':
                case 'elementType':
                case 'status':
                case 'name':
                case 'email':
                case 'url':
                case 'ipAddress':
                case 'userAgent':
                case 'comment':
                    $element->$handle = $dataValue;
                    break;
                case 'elementId';
                    $element->$handle = $this->_prepareOwnerForElement($value, $data);
                    break;
                case 'userId';
                    $element->$handle = $this->_prepareAuthorForElement($dataValue);
                    break;
                default:
                    continue 2;
            }

            // Update the original data in our feed - for clarity in debugging
            $data[$handle] = $element->$handle;
        }

        // Set default author if not set
        if (!$element->userId) {
            $user = craft()->userSession->getUser();
            $element->userId = ($element->userId ? $element->userId : ($user ? $user->id : 1));

            // Update the original data in our feed - for clarity in debugging
            $data['userId'] = $element->userId;
        }

        return $element;
    }

    public function save(BaseElementModel &$element, array $data, $settings)
    {
        // Put this back for now, until we can figure out a better solution with required fields
        $element->setContentFromPost($data);

        // Are we targeting a specific locale here? If so, we create an essentially blank element
        // for the primary locale, and instead create a locale for the targeted locale
        if (isset($settings['locale']) && $settings['locale']) {
            // Save the default locale element empty
            if (craft()->comments->saveComment($element)) {
                // Now get the successfully saved (empty) element, and set content on that instead
                $elementLocale = craft()->comments->getCommentById($element->id, $settings['locale']);
                $elementLocale->setContentFromPost($data);

                // Save the locale entry
                if (craft()->comments->saveComment($elementLocale)) {
                    return true;
                } else {
                    if ($elementLocale->getErrors()) {
                        throw new Exception(json_encode($elementLocale->getErrors()));
                    } else {
                        throw new Exception(Craft::t('Unknown Element error occurred.'));
                    }
                }
            } else {
                if ($element->getErrors()) {
                    throw new Exception(json_encode($element->getErrors()));
                } else {
                    throw new Exception(Craft::t('Unknown Element error occurred.'));
                }
            }

            return false;
        } else {
            return craft()->comments->saveComment($element);
        }
    }

    public function afterSave(BaseElementModel $element, array $data, $settings)
    {

    }


    // Private Methods
    // =========================================================================

    private function _prepareAuthorForElement($author)
    {
        if (!is_numeric($author)) {
            $criteria = craft()->elements->getCriteria(ElementType::User);
            $criteria->search = $author;
            $authorUser = $criteria->first();
            
            if ($authorUser) {
                $author = $authorUser->id;
            } else {
                $user = craft()->users->getUserByUsernameOrEmail($author);
                $author = $user ? $user->id : 1;
            }
        }

        return $author;
    }

    private function _prepareOwnerForElement($value, $data)
    {
        $elementType = Hash::get($data, 'elementType.data');
        $dataValue = Hash::get($value, 'data');
        $matchAttribute = Hash::get($value, 'options.match');

        if (!$elementType || !$dataValue || !$matchAttribute) {
            return $dataValue;
        }

        if (is_numeric($dataValue)) {
            return $dataValue;
        }

        $criteria = craft()->elements->getCriteria($elementType);
        $criteria->$matchAttribute = $dataValue;
        $elements = $criteria->find();

        if (count($elements)) {
            return $elements[0]->id;
        }

        return $dataValue;
    }

}
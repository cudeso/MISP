<?php
App::uses('AppModel', 'Model');

class EventReport extends AppModel
{
    public $actsAs = array(
        'Containable',
        'SysLogLogable.SysLogLogable' => array(
            'userModel' => 'User',
            'userKey' => 'user_id',
            'change' => 'full'
        ),
        'Regexp' => array('fields' => array('value')),
    );

    public $validate = array(
        'event_id' => array(
            'numeric' => array(
                'rule' => array('numeric')
            )
        ),
        'uuid' => array(
            'uuid' => array(
                'rule' => array('custom', '/^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$/'),
                'message' => 'Please provide a valid UUID'
            ),
            'unique' => array(
                'rule' => 'isUnique',
                'message' => 'The UUID provided is not unique',
                'required' => 'create'
            )
        ),
        'distribution' => array(
            'rule' => array('inList', array('0', '1', '2', '3', '4', '5')),
            'message' => 'Options: Your organisation only, This community only, Connected communities, All communities, Sharing group, Inherit event',
            'required' => true
        )
    );

    public $captureFields = array('uuid', 'name', 'content', 'distribution', 'sharing_group_id', 'timestamp', 'deleted', 'event_id');
    public $defaultContain = array(
        'SharingGroup' => array('fields' => array('id', 'name', 'uuid')),
        'Event' => array(
            'fields' =>  array('Event.id', 'Event.orgc_id', 'Event.org_id', 'Event.info', 'Event.user_id', 'Event.date'),
            'Orgc' => array('fields' => array('Orgc.id', 'Orgc.name')),
            'Org' => array('fields' => array('Org.id', 'Org.name'))
        )
    );

    public $belongsTo = array(
        'Event' => array(
            'className' => 'Event',
            'foreignKey' => 'event_id'
        ),
        'SharingGroup' => array(
            'className' => 'SharingGroup',
            'foreignKey' => 'sharing_group_id'
        ),
    );

    public function beforeValidate($options = array())
    {
        parent::beforeValidate();
        // generate UUID if it doesn't exist
        if (empty($this->data['EventReport']['uuid'])) {
            $this->data['EventReport']['uuid'] = CakeText::uuid();
        }
        // generate timestamp if it doesn't exist
        if (empty($this->data['EventReport']['timestamp'])) {
            $date = new DateTime();
            $this->data['EventReport']['timestamp'] = $date->getTimestamp();
        }
        if ($this->data['EventReport']['distribution'] != 4) {
            $this->data['EventReport']['sharing_group_id'] = 0;
        }
        // Set defaults for when some of the mandatory fields don't have defaults
        // These fields all have sane defaults either based on another field, or due to server settings
        if (!isset($this->data['EventReport']['distribution'])) {
            $this->data['EventReport']['distribution'] = Configure::read('MISP.default_attribute_distribution');
            if ($report['EventReport']['distribution'] == 'event') {
                $report['EventReport']['distribution'] = 5;
            }
        }
        return true;
    }
    /**
     * captureReport Gets a report then save it
     *
     * @param  array $user
     * @param  array $report
     * @param  int|string $eventId
     * @return array Any errors preventing the capture
     */
    public function captureReport(array $user, array $report, $eventId)
    {
        $this->Log = ClassRegistry::init('Log');
        if (!isset($report['EventReport'])) {
            $report = ['EventReport' => $report];
        }
        $report['EventReport']['event_id'] = $eventId;
        $report = $this->captureSG($user, $report);
        $this->create();
        $errors = $this->saveAndReturnErrors($report, ['fieldList' => $this->captureFields]);
        if (!empty($errors)) {
            $this->Log->createLogEntry($user, 'add', 'EventReport', 0,
                __('Event Report dropped due to validation for Event report %s failed: %s', $report['EventReport']['uuid'], ' failed: ' . $report['EventReport']['name']),
                __('Validation errors: %s.%sFull report: %s', json_encode($errors), PHP_EOL, json_encode($report['EventReport']))
            );
        }
        return $errors;
    }
    
    /**
     * addReport Add a report
     *
     * @param  array $user
     * @param  array $report
     * @param  int|string $eventId
     * @return array Any errors preventing the addition
     */
    public function addReport(array $user, array $report, $eventId)
    {
        $errors = $this->captureReport($user, $report, $eventId);
        if (empty($errors)) {
            $this->Event->unpublishEvent($eventId);
        }
        return $errors;
    }
    
    /**
     * editReport Edit a report
     *
     * @param  array $user
     * @param  array $report
     * @param  int|string $eventId
     * @param  bool  $fromPull
     * @param  bool  $nothingToChange
     * @return array Any errors preventing the edition
     */
    public function editReport(array $user, array $report, $eventId, $fromPull = false, &$nothingToChange = false)
    {
        $errors = array();
        if (!isset($report['EventReport']['uuid'])) {
            $errors[] = __('Event Report doesn\'t have an UUID');
            return $errors;
        }
        $report['EventReport']['event_id'] = $eventId;
        $existingReport = $this->find('first', array(
            'conditions' => array('EventReport.uuid' => $report['EventReport']['uuid']),
            'recursive' => -1,
        ));
        if (empty($existingReport)) {
            if ($fromPull) {
                return $this->captureReport($user, $report, $eventId);
            } else {
                $errors[] = __('Event Report not found.');
                return $errors;
            }
        }

        if ($fromPull) {
            if (isset($report['EventReport']['timestamp'])) {
                if ($report['EventReport']['timestamp'] <= $existingReport['EventReport']['timestamp']) {
                    $nothingToChange = true;
                    return array();
                }
            }
        } else {
            unset($report['EventReport']['timestamp']);
        }
        $errors = $this->saveAndReturnErrors($report, ['fieldList' => $this->captureFields], $errors);
        if (empty($errors)) {
            $this->Event->unpublishEvent($eventId);
        }
        return $errors;
    }

    /**
     * deleteReport ACL-aware method to delete the report.
     *
     * @param  array $user
     * @param  int|string $id
     * @param  bool $hard
     * @return array Any errors preventing the deletion
     */
    public function deleteReport(array $user, $report, $hard=false)
    {
        $report = $this->fetchIfAuthorized($user, $report, 'delete', $throwErrors=true, $full=false);
        $errors = [];
        if ($hard) {
            $deleted = $this->delete($report['EventReport']['id'], true);
            if (!$deleted) {
                $errors[] = __('Failed to delete report');
            }
        } else {
            $report['EventReport']['deleted'] = true;
            $errors = $this->saveAndReturnErrors($report, ['fieldList' => ['deleted']]);
        }
        if (empty($errors)) {
            $this->Event->unpublishEvent($report['EventReport']['event_id']);
        }
        return $errors;
    }
    
    /**
     * restoreReport ACL-aware method to restore a report.
     *
     * @param  array $user
     * @param  int|string $id
     * @return array Any errors preventing the restoration
     */
    public function restoreReport(array $user, $id)
    {
        $report = $this->fetchIfAuthorized($user, $id, 'edit', $throwErrors=true, $full=false);
        $report['EventReport']['deleted'] = false;
        $errors = $this->saveAndReturnErrors($report, ['fieldList' => ['deleted']]);
        if (empty($errors)) {
            $this->Event->unpublishEvent($report['EventReport']['event_id']);
        }
        return $errors;
    }

    private function captureSG(array $user, array $report)
    {
        $this->Event = ClassRegistry::init('Event');
        if (isset($report['EventReport']['distribution']) && $report['EventReport']['distribution'] == 4) {
            $report['EventReport'] = $this->Event->__captureSGForElement($report['EventReport'], $user);
        }
        return $report;
    }
    
    /**
     * buildACLConditions Generate ACL conditions for viewing the report
     *
     * @param  array $user
     * @return array
     */
    public function buildACLConditions(array $user)
    {
        $this->Event = ClassRegistry::init('Event');
        $conditions = array();
        if (!$user['Role']['perm_site_admin']) {
            $sgids = $this->Event->cacheSgids($user, true);
            $eventConditions = $this->Event->createEventConditions($user);
            $conditions = array(
                'AND' => array(
                    $eventConditions['AND'],
                    array(
                        'OR' => array(
                            'Event.org_id' => $user['org_id'],
                            'EventReport.distribution' => array('1', '2', '3', '5'),
                            'AND '=> array(
                                'EventReport.distribution' => 4,
                                'EventReport.sharing_group_id' => $sgids,
                            )
                        )
                    )
                )
            );
        }
        return $conditions;
    }

    /**
     * fetchById Simple ACL-aware method to fetch a report by Id or UUID
     *
     * @param  array $user
     * @param  int|string $reportId
     * @param  bool  $throwErrors
     * @param  bool  $full
     * @return array
     */
    public function simpleFetchById(array $user, $reportId, $throwErrors=true, $full=false)
    {
        if (Validation::uuid($reportId)) {
            $temp = $this->find('first', array(
                'recursive' => -1,
                'fields' => array("EventReport.id", "EventReport.uuid"),
                'conditions' => array("EventReport.uuid" => $reportId)
            ));
            if (empty($temp)) {
                if ($throwErrors) {
                    throw new NotFoundException(__('Invalid report'));
                }
                return array();
            }
            $reportId = $temp['EventReport']['id'];
        } elseif (!is_numeric($reportId)) {
            if ($throwErrors) {
                throw new NotFoundException(__('Invalid report'));
            }
            return array();
        }
        $options = array('conditions' => array("EventReport.id" => $reportId));
        $report = $this->fetchReports($user, $options, $full=$full);
        if (!empty($report)) {
            return $report[0];
        }
        if ($throwErrors) {
            throw new NotFoundException(__('Invalid report'));
        }
        return array();
    }
    
    /**
     * fetchReports ACL-aware method. Basically find with ACL
     *
     * @param  array $user
     * @param  array $options
     * @param  bool  $full
     * @return void
     */
    public function fetchReports(array $user, array $options = array(), $full=false)
    {
        $params = array(
            'conditions' => $this->buildACLConditions($user),
            'contain' => $this->defaultContain,
            'recursive' => -1
        );
        if ($full) {
            $params['recursive'] = 1;
        }
        if (isset($options['fields'])) {
            $params['fields'] = $options['fields'];
        }
        if (isset($options['conditions'])) {
            $params['conditions']['AND'][] = $options['conditions'];
        }
        if (isset($options['group'])) {
            $params['group'] = empty($options['group']) ? $options['group'] : false;
        }
        $reports = $this->find('all', $params);
        return $reports;
    }

    /**
     * fetchIfAuthorized Fetches a report and checks if the user has the authorization to perform the requested operation
     *
     * @param  array $user
     * @param  int|string|array $report
     * @param  mixed $authorizations the requested actions to be performed on the report
     * @param  bool  $throwErrors Should the function throws excpetion if users is not allowed to perform the action
     * @param  bool  $full
     * @return array The report or an error message
     */
    public function fetchIfAuthorized(array $user, $report, $authorizations, $throwErrors=true, $full=false)
    {
        $authorizations = is_array($authorizations) ? $authorizations : array($authorizations);
        $possibleAuthorizations = array('view', 'edit', 'delete');
        if (!empty(array_diff($authorizations, $possibleAuthorizations))) {
            throw new NotFoundException(__('Invalid authorization requested'));
        }
        if (isset($report['uuid'])) {
            $report['EventReport'] = $report;
        }
        if (!isset($report['EventReport']['uuid'])) {
            $report = $this->simpleFetchById($user, $report, $throwErrors=$throwErrors, $full=$full);
            if (empty($report)) {
                $message = __('Invalid report');
                return array('authorized' => false, 'error' => $message);
            }
        }
        if ($user['Role']['perm_site_admin']) {
            return $report;
        }

        if (in_array('view', $authorizations) && count($authorizations) == 1) {
            return $report;
        } else {
            if (in_array('edit', $authorizations) || in_array('delete', $authorizations)) {
                $checkResult = $this->canEditReport($user, $report);
                if ($checkResult !== true) {
                    if ($throwErrors) {
                        throw new UnauthorizedException($checkResult);
                    }
                    return array('authorized' => false, 'error' => $checkResult);
                }
            }
            return $report;
        }
    }

    public function canEditReport(array $user, array $report)
    {
        if ($user['Role']['perm_site_admin']) {
            return true;
        }
        if (empty($report['Event'])) {
            return __('Could not find associated event');
        }
        if ($report['Event']['orgc_id'] != $user['org_id']) {
            return __('Only the creator organisation of the event can modify the report');
        }
        return true;
    }
    
    public function reArrangeReport(array $report)
    {
        $rearrangeObjects = array('Event', 'SharingGroup');
        if (isset($report['EventReport'])) {
            foreach ($rearrangeObjects as $ro) {
                if (isset($report[$ro]) && !is_null($report[$ro]['id'])) {
                    $report['EventReport'][$ro] = $report[$ro];
                }
                unset($report[$ro]);
            }
        }
        return $report;
    }

    /**
     * getProxyMISPElements Extract MISP Elements from an event and make them accessible by their UUID
     *
     * @param  array $user
     * @param  int|string $eventid
     * @return array
     */
    public function getProxyMISPElements(array $user, $eventid)
    {
        $event = $this->Event->fetchEvent($user, ['eventid' => $eventid]);
        if (empty($event)) {
            throw new NotFoundException(__('Invalid Event'));
        }
        $event = $event[0];
        $parentEventId = $this->Event->fetchSimpleEventIds($user, ['conditions' => [
            'uuid' => $event['Event']['extends_uuid']
        ]]);
        $completeEvent = $event;
        if (!empty($parentEventId)) {
            $parentEvent = $this->Event->fetchEvent($user, ['eventid' => $parentEventId, 'extended' => true]);
            if (!empty($parentEvent)) {
                $parentEvent = $parentEvent[0];
                $completeEvent = $parentEvent;
            }
        }
        $attributes = Hash::combine($completeEvent, 'Attribute.{n}.uuid', 'Attribute.{n}');
        $this->AttributeTag = ClassRegistry::init('AttributeTag');
        $allTagNames = Hash::combine($event['EventTag'], '{n}.Tag.name', '{n}.Tag');
        $attributeTags = Hash::combine($this->AttributeTag->getAttributesTags($completeEvent['Attribute'], true), '{n}.name', '{n}');
        $parentEventTags = !empty($parentEvent) ? Hash::combine($parentEvent['EventTag'], '{n}.Tag.name', '{n}.Tag') : [];
        $allTagNames = array_merge($allTagNames, $attributeTags, $parentEventTags);
        $objects = [];
        $templateConditions = [];
        $recordedConditions = [];
        foreach ($completeEvent['Object'] as $k => $object) {
            $objects[$object['uuid']] = $object;
            $objectAttributes = [];
            foreach ($object['Attribute'] as $i => $objectAttribute) {
                $objectAttributes[$objectAttribute['uuid']] = $object['Attribute'][$i];
                $objectAttributes[$objectAttribute['uuid']]['object_uuid'] = $object['uuid'];
            }
            $attributes = array_merge($attributes, $objectAttributes);
            $objectAttributeTags = Hash::combine($this->AttributeTag->getAttributesTags($object['Attribute'], true), '{n}.name', '{n}');
            $allTagNames = array_merge($allTagNames, $objectAttributeTags);
            $uniqueCondition = sprintf('%s.%s', $object['template_uuid'], $object['template_version']);
            if (!isset($recordedConditions[$uniqueCondition])) {
                $templateConditions['OR'][] = [
                    'ObjectTemplate.uuid' => $object['template_uuid'],
                    'ObjectTemplate.version' => $object['template_version']
                ];
                $recordedConditions[$uniqueCondition] = true;
            }
        }
        $templateConditions = empty($templateConditions) ? ['ObjectTemplate.id' => 0] : $templateConditions;
        $this->ObjectTemplate = ClassRegistry::init('ObjectTemplate');
        $templates = $this->ObjectTemplate->find('all', array(
            'conditions' => $templateConditions,
            'recursive' => -1,
            'contain' => array(
                'ObjectTemplateElement' => [
                    'order' => ['ui-priority' => 'DESC'],
                    'fields' => ['object_relation', 'type', 'ui-priority']
                ]
            )
        ));
        $objectTemplates = [];
        foreach ($templates as $template) {
            $objectTemplates[sprintf('%s.%s', $template['ObjectTemplate']['uuid'], $template['ObjectTemplate']['version'])] = $template;
        }
        $this->Galaxy = ClassRegistry::init('Galaxy');
        $allowedGalaxies = $this->Galaxy->getAllowedMatrixGalaxies();
        $allowedGalaxies = Hash::combine($allowedGalaxies, '{n}.Galaxy.uuid', '{n}.Galaxy');
        $proxyMISPElements = [
            'attribute' => $attributes,
            'object' => $objects,
            'objectTemplates' => $objectTemplates,
            'galaxymatrix' => $allowedGalaxies,
            'tagname' => $allTagNames
        ];
        return $proxyMISPElements;
    }

    private function saveAndReturnErrors($data, $saveOptions = [], $errors = [])
    {
        $saveSuccess = $this->save($data, $saveOptions);
        if (!$saveSuccess) {
            foreach ($this->validationErrors as $validationError) {
                $errors[] = $validationError[0];
            }
        }
        return $errors;
    }

    public function applySuggestions($user, $report, $contentWithSuggestions, $suggestionsMapping) {
        $errors = [];
        $replacedContent = $contentWithSuggestions;
        $success = 0;
        foreach ($suggestionsMapping as $value => $suggestedAttribute) {
            $suggestedAttribute['value'] = $value;
            $savedAttribute = $this->createAttributeFromSuggestion($user, $report, $suggestedAttribute);
            if (empty($savedAttribute['errors'])) {
                $success++;
                $replacedContent = $this->applySuggestionsInText($replacedContent, $savedAttribute['attribute'], $value);
            } else {
                $replacedContent = $this->revertToOriginalInText($replacedContent, $value);
                $errors[] = $savedAttribute['errors'];
            }
        }
        if ($success > 0) {
            $report['EventReport']['content'] = $replacedContent;
            $editErrors = $this->editReport($user, $report, $report['EventReport']['event_id']);
            if (!empty($editErrors)) {
                $errors[] = $editErrors;
            }
        }
        return $errors;
    }
    
    public function applySuggestionsInText($contentWithSuggestions, $attribute, $value)
    {
        $textToBeReplaced = sprintf('@[suggestion](%s)', $value);
        $textToInject = sprintf('@[attribute](%s)', $attribute['Attribute']['uuid']);
        $replacedContent = str_replace($textToBeReplaced, $textToInject, $contentWithSuggestions);
        return $replacedContent;
    }

    public function revertToOriginalInText($contentWithSuggestions, $value)
    {
        $textToBeReplaced = sprintf('@[suggestion](%s)', $value);
        $textToInject = $value;
        $replacedContent = str_replace($textToBeReplaced, $textToInject, $contentWithSuggestions);
        return $replacedContent;
    }

    private function createAttributeFromSuggestion($user, $report, $suggestedAttribute)
    {
        $errors = [];
        $attribute = [
            'event_id' => $report['EventReport']['event_id'],
            'distribution' => 5,
            'category' => $suggestedAttribute['category'],
            'type' => $suggestedAttribute['type'],
            'value' => $suggestedAttribute['value'],
            'to_ids' => $suggestedAttribute['to_ids'],
        ];
        $validationErrors = array();
        $this->Event->Attribute->captureAttribute($attribute, $report['EventReport']['event_id'], $user, false, false, false, $validationErrors);
        $savedAttribute = false;
        if (!empty($validationErrors)) {
            $errors = $validationErrors;
        } else {
            $savedAttribute = $this->Event->Attribute->find('first', array(
                'recursive' => -1,
                'conditions' => array('Attribute.id' => $this->Event->Attribute->id),
            ));
        }
        return [
            'errors' => $errors,
            'attribute' => $savedAttribute
        ];
    }
    
    /**
     * transformFreeTextIntoReplacement
     *
     * @param  array $user
     * @param  array $report
     * @param  array $complexTypeToolResult Uses the complex type tool output to support import regex replacements.
     *                                      Another solution would be to run the regex replacement on each token of the report which is too heavy
     * @return array
     */
    public function transformFreeTextIntoReplacement(array $user, array $report, array $complexTypeToolResult)
    {
        $complexTypeToolResultWithImportRegex = $this->injectImportRegexOnComplexTypeToolResult($complexTypeToolResult);
        $valueToValueWithRegex = Hash::combine($complexTypeToolResultWithImportRegex, '{n}.valueWithImportRegex', '{n}.value');
        $proxyElements = $this->getProxyMISPElements($user, $report['EventReport']['event_id']);
        $originalContent = $report['EventReport']['content'];
        $content = $originalContent;
        $replacedValues = [];
        foreach ($proxyElements['attribute'] as $uuid => $attribute) {
            $count = 0;
            $textToInject = sprintf('@[attribute](%s)', $uuid);
            $content = str_replace($attribute['value'], $textToInject, $content, $count);
            if ($count > 0 || strpos($originalContent, $attribute['value'])) { // Check if the value has been replaced by the first match
                if (!isset($replacedValues[$attribute['value']])) {
                    $replacedValues[$attribute['value']] = [
                        'attributeUUIDs' => [$uuid],
                        'valueInReport' => $attribute['value'],
                    ];
                } else {
                    $replacedValues[$attribute['value']]['attributeUUIDs'][] = $uuid;
                }
                $count = 0;
            }
            if (isset($valueToValueWithRegex[$attribute['value']]) && $valueToValueWithRegex[$attribute['value']] != $attribute['value']) {
                $content = str_replace($valueToValueWithRegex[$attribute['value']], $textToInject, $content, $count);
                if ($count > 0 || strpos($originalContent, $valueToValueWithRegex[$attribute['value']])) {
                    if (!isset($replacedValues[$attribute['value']])) {
                        $replacedValues[$attribute['value']] = [
                            'attributeUUIDs' => [$uuid],
                            'valueInReport' => $valueToValueWithRegex[$attribute['value']],
                        ];
                    } else {
                        $replacedValues[$attribute['value']]['attributeUUIDs'][] = $uuid;
                    }
                }
            }
        }
        return [
            'contentWithReplacements' => $content,
            'replacedValues' => $replacedValues
        ];
    }

    public function transformFreeTextIntoSuggestion($content, $complexTypeToolResult)
    {
        $replacedContent = $content;
        $suggestionsMapping = [];
        $typeToCategoryMapping = $this->Event->Attribute->typeToCategoryMapping();
        foreach ($complexTypeToolResult as $i => $complexTypeToolEntry) {
            $textToBeReplaced = $complexTypeToolEntry['value'];
            $textToInject = sprintf('@[suggestion](%s)', $textToBeReplaced);
            $suggestionsMapping[$textToBeReplaced] = [
                'category' => $typeToCategoryMapping[$complexTypeToolEntry['default_type']][0],
                'type' => $complexTypeToolEntry['default_type'],
                'value' => $textToBeReplaced,
                'to_ids' => $complexTypeToolEntry['to_ids'],
            ];
            $replacedContent = str_replace($textToBeReplaced, $textToInject, $replacedContent);
        }
        return [
            'contentWithSuggestions' => $replacedContent,
            'suggestionsMapping' => $suggestionsMapping
        ];
    }

    public function injectImportRegexOnComplexTypeToolResult($complexTypeToolResult) {
        foreach ($complexTypeToolResult as $i => $complexTypeToolEntry) {
            $transformedValue = $this->runRegexp($complexTypeToolEntry['default_type'], $complexTypeToolEntry['value']);
            if ($transformedValue !== false) {
                $complexTypeToolResult[$i]['valueWithImportRegex'] = $transformedValue;
            }
        }
        return $complexTypeToolResult;
    }

    public function getComplexTypeToolResultFromReport($content)
    {
        App::uses('ComplexTypeTool', 'Tools');
        $complexTypeTool = new ComplexTypeTool();
        $this->Warninglist = ClassRegistry::init('Warninglist');
        $complexTypeTool->setTLDs($this->Warninglist->fetchTLDLists());
        $complexTypeToolResult = $complexTypeTool->checkComplexRouter($content, 'freetext');
        return $complexTypeToolResult;
    }

    public function getComplexTypeToolResultWithReplacementsFromReport($user, $report)
    {
        $complexTypeToolResult = $this->getComplexTypeToolResultFromReport($report['EventReport']['content']);
        $replacementResult = $this->transformFreeTextIntoReplacement($user, $report, $complexTypeToolResult);
        $complexTypeToolResult = $this->getComplexTypeToolResultFromReport($replacementResult['contentWithReplacements']);
        return [
            'complexTypeToolResult' => $complexTypeToolResult,
            'replacementResult' => $replacementResult,
        ];
    }
    
    /**
     * extractWithReplacementsFromReport Extract context information from report with special care for ATT&CK
     *
     * @param  array $user
     * @param  array $report
     * @return array
     */
    public function extractWithReplacementsFromReport(array $user, array $report, $options = [])
    {
        $baseOptions = [
            'replace' => false,
            'tags' => true,
            'synonyms' => true,
            'synonyms_min_characters' => 4,
            'prune_deprecated' => true,
            'attack' => true,
        ];
        $options = array_merge($baseOptions, $options);
        $originalContent = $report['EventReport']['content'];
        $replacedContext = [];
        $content = $originalContent;
        $this->GalaxyCluster = ClassRegistry::init('GalaxyCluster');
        $mitreAttackGalaxyId = $this->GalaxyCluster->Galaxy->getMitreAttackGalaxyId();
        $clusterContain = ['Tag'];

        if ($options['prune_deprecated']) {
            $clusterContain['Galaxy'] = ['conditions' => ['Galaxy.namespace !=' => 'deprecated']];
        }
        if ($options['synonyms']) {
            $clusterContain['GalaxyElement'] = ['conditions' => ['GalaxyElement.key' => 'synonyms']];
        }
        $clusterConditions = [];
        if ($options['attack']) {
            $clusterConditions = ['GalaxyCluster.galaxy_id !=' => $mitreAttackGalaxyId];
        }
        $clusters = $this->GalaxyCluster->find('all', [
            'conditions' => $clusterConditions,
            'contain' => $clusterContain
        ]);

        if ($options['tags']) {
            $this->Tag = ClassRegistry::init('Tag');
            $tags = $this->Tag->fetchUsableTags($user);
            foreach ($tags as $i => $tag) {
                $tagName = $tag['Tag']['name'];
                $textToInject = sprintf('@[tag](%s)', $tagName);
                $count = 0;
                if ($options['replace']) {
                    $count = 0;
                    $content = str_replace($tagName, $textToInject, $content, $count);
                } else {
                    $count = strpos($originalContent, $tagName) === false ? -1 : 1;
                }
                if ($count > 0) {
                    $replacedContext[$tagName][$tagName] = $tag['Tag'];
                } else {
                    $tagNameUpper = strtoupper($tagName);
                    if ($options['replace']) {
                        $count = 0;
                        $content = str_replace($tagNameUpper, $textToInject, $content, $count);
                    } else {
                        $count = strpos($originalContent, $tagNameUpper) === false ? -1 : 1;
                    }
                    if ($count > 0) {
                        $replacedContext[$tagNameUpper][$tagName] = $tag['Tag'];
                    }
                }
            }
        }

        foreach ($clusters as $i => $cluster) {
            $cluster['GalaxyCluster']['colour'] = '#0088cc';
            $tagName = $cluster['GalaxyCluster']['tag_name'];
            $textToInject = sprintf('@[tag](%s)', $tagName);
            if ($options['replace']) {
                $count = 0;
                $content = str_replace($tagName, $textToInject, $content, $count);
            } else {
                $count = strpos($originalContent, $tagName) === false ? -1 : 1;
            }
            if ($count > 0) {
                $replacedContext[$tagName][$tagName] = $cluster['GalaxyCluster'];
            }
            if ($options['replace']) {
                $count = 0;
                $content = str_replace($cluster['GalaxyCluster']['value'], $textToInject, $content, $count);
            } else {
                $count = strpos($originalContent, $cluster['GalaxyCluster']['value']) === false ? -1 : 1;
            }
            if ($count > 0) {
                $replacedContext[$cluster['GalaxyCluster']['value']][$tagName] = $cluster['GalaxyCluster'];
            }
            if ($options['synonyms']) {
                foreach ($cluster['GalaxyElement'] as $j => $element) {
                    if (strlen($element['value']) >= $options['synonyms_min_characters']) {
                        if ($options['replace']) {
                            $count = 0;
                            $content = str_replace($element['value'], $textToInject, $content, $count);
                        } else {
                            $count = strpos($originalContent, $element['value']) === false ? -1 : 1;
                        }
                        if ($count > 0) {
                            $replacedContext[$element['value']][$tagName] = $cluster['GalaxyCluster'];
                        }
                    }
                }
            }
        }

        if ($options['attack']) {
            unset($clusterContain['Galaxy']);
            $attackClusters = $this->GalaxyCluster->find('all', [
                'conditions' => ['GalaxyCluster.galaxy_id' => $mitreAttackGalaxyId],
                'contain' => $clusterContain
            ]);
            foreach ($attackClusters as $i => $cluster) {
                $cluster['GalaxyCluster']['colour'] = '#0088cc';
                $tagName = $cluster['GalaxyCluster']['tag_name'];
                $textToInject = sprintf('@[tag](%s)', $tagName);
                if ($options['replace']) {
                    $count = 0;
                    $content = str_replace($cluster['GalaxyCluster']['value'], $textToInject, $content, $count);
                } else {
                    $count = strpos($originalContent, $cluster['GalaxyCluster']['value']) === false ? -1 : 1;
                }
                if ($count > 0) {
                    $replacedContext[$cluster['GalaxyCluster']['value']][$tagName] = $cluster['GalaxyCluster'];
                } else {
                    $clusterParts = explode(' - ', $cluster['GalaxyCluster']['value']);
                    if ($options['replace']) {
                        $count = 0;
                        $content = str_replace($clusterParts[0], $textToInject, $content, $count);
                    } else {
                        $count = strpos($originalContent, $clusterParts[0]) === false ? -1 : 1;
                    }
                    if ($count > 0) {
                        $replacedContext[$clusterParts[0]][$tagName] = $cluster['GalaxyCluster'];
                    } else {
                        if ($options['replace']) {
                            $count = 0;
                            $content = str_replace($clusterParts[1], $textToInject, $content, $count);
                        } else {
                            $count = strpos($originalContent, $clusterParts[1]) === false ? -1 : 1;
                        }
                        if ($count > 0) {
                            $replacedContext[$clusterParts[1]][$tagName] = $cluster['GalaxyCluster'];
                        }
                    }
                }
            }
        }
        return [
            'replacedContext' => $replacedContext,
            'contentWithReplacements' => $content
        ];
    }
}

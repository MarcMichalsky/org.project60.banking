<?php

/*-------------------------------------------------------+
| Project 60 - CiviBanking - PHPUnit tests               |
| Copyright (C) 2020 SYSTOPIA                            |
| Author: B. Zschiedrich (zschiedrich@systopia.de)       |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

use Civi\API\Exception\NotImplementedException;
use Civi\Test\Api3TestTrait;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_Banking_ExtensionUtil as E;

/**
 * The base class for all the unit tests.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class CRM_Banking_TestBase extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    HookInterface,
    TransactionalInterface
{
    use Api3TestTrait {
        callAPISuccess as protected traitCallAPISuccess;
    }

    /** The primary fields of the transaction are the fields of its database table. All other fields will be
     *  written as JSON to "data_parsed".
     */
    const PRIMARY_TRANSACTION_FIELDS = [
        'version', 'debug', 'amount', 'bank_reference', 'value_date', 'booking_date', 'currency', 'type_id',
        'status_id', 'data_raw', 'data_parsed', 'ba_id', 'party_ba_id', 'tx_batch_id', 'sequence'
    ];

    /**
     * The gap between the weight of two matchers.
     */
    const MATCHER_WEIGHT_STEP = 10;

    /**
     * Used to generate unique transaction references.
     */
    protected $transactionReferenceCounter = 0;

    /**
     * The weight for the next matcher.
     */
    protected $matcherWeight = 10;

    /**
     * The list of created transactions.
     * This is filled in "createTransaction" and used in "runMatchers" as it needs a list of transactions.
     */
    protected $transactionIds = [];

    public function setUpHeadless(): Civi\Test\CiviEnvBuilder
    {
        // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
        // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
        return \Civi\Test::headless()
            ->installMe(__DIR__)
            ->apply();
    }

    /**
     * This is called before each test.
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->transactionReferenceCounter = 0;
        $this->matcherWeight = self::MATCHER_WEIGHT_STEP;
        $this->transactionIds = [];
    }

    /**
     * This is called after each test.
     */
    public function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Remove 'xdebug' result key set by Civi\API\Subscriber\XDebugSubscriber because it breaks some tests
     * when xdebug is present, and we do not need it.
     *
     * @param $entity
     * @param $action
     * @param $params
     * @param null $checkAgainst
     *
     * @return array|int
     */
    protected function callAPISuccess(string $entity, string $action, array $params, $checkAgainst = null)
    {
        $result = $this->traitCallAPISuccess($entity, $action, $params, $checkAgainst);

        if (is_array($result)) {
            unset($result['xdebug']);
        }

        return $result;
    }

    /**
     * Create a contact and return its ID.
     *
     * @return int The ID of the created contact.
     */
    protected function createContact(): int
    {
        $contact = $this->callAPISuccess(
            'Contact',
            'create',
            [
                'contact_type' => 'Individual',
                'email' => 'unittests@banking.project60.org',
            ]
        );

        $contactId = $contact['id'];

        return $contactId;
    }

    /**
     * Create a transaction and return its ID.
     *
     * @param array $parameters The parameters for the transaction. Only set values will overwrite defaults.
     *
     * @return int The ID of the created transaction.
     */
    protected function createTransaction(array $parameters = []): int
    {
        $today = date('Y-m-d');

        $defaults = [
            'version' => 3,
            'bank_reference' => 'TestBankReference-' . $this->transactionReferenceCounter,
            'booking_date' => $today,
            'value_date' => $today,
            'currency' => 'EUR',
            'sequence' => $this->transactionReferenceCounter,
        ];

        $this->transactionReferenceCounter++;

        $transaction = array_merge($defaults, $parameters);

        // Fill parsed data:
        $parsedData = [];
        foreach ($transaction as $key => $value) {
            if (!in_array($key, self::PRIMARY_TRANSACTION_FIELDS)) {
                $parsedData[$key] = $value;
                unset($transaction[$key]);
            }
        }
        $transaction['data_parsed'] = json_encode($parsedData);

        $result = $this->callAPISuccess('BankingTransaction', 'create', $transaction);

        // Add the transaction to the transaction list which is used to run the matcher for all transactions:
        $this->transactionIds[] = $result['id'];

        return $result['id'];
    }

    /**
     * Get a transaction by its ID.
     *
     * @param int $id
     *
     * @return array The transaction.
     */
    protected function getTransaction(int $id): array
    {
        $result = $this->callAPISuccess(
            'BankingTransaction',
            'getsingle',
            [
                'id' => $id
            ]
        );

        unset($result['is_error']);

        return $result;
    }

    /**
     * Get the data_parsed array from a transaction
     *
     * @param int $id
     *   transaction ID
     *
     * @return array
     *   (extracted) contents of data_parsed
     */
    protected function getTransactionDataParsed(int $id): array
    {
        $transaction = $this->getTransaction($id);
        $this->assertArrayHasKey('data_parsed', $transaction, 'No data_parsed set');
        $parsed_data = json_decode($transaction['data_parsed'], true);
        $this->assertNotNull($parsed_data, 'Invalid data_parsed blob');
        return $parsed_data;
    }

    /**
     * Get the latest contribution.
     *
     * @return array The contribution.
     */
    protected function getLatestContribution()
    {
        $contribution = $this->callAPISuccessGetSingle(
            'Contribution',
            [
                'options' => [
                    'sort' => 'id DESC',
                    'limit' => 1,
                ],
            ]
        );

        // TODO: A special error message for an empty result would be very handy.

        return $contribution;
    }

    protected function getSuggestions($transactionId, $matcherId = null): array
    {
        throw new NotImplementedException('TODO: Implement!');
    }

    /**
     * Create a matcher and return its ID.
     *
     * @param string $type The matcher/analyser type, e.g. "match".
     * @param string $class The matcher/analyser class, e.g. "analyser_regex".
     * @param string $configuration The configuration for the matcher. Only set values will overwrite defaults.
     * @param string $parameters The parameters for the matcher. Only set values will overwrite defaults.
     *
     * @return int The matcher ID.
     */
    protected function createMatcher(
        string $type,
        string $class,
        array $configuration = [],
        array $parameters = []
    ): int {
        $typeId = $this->matcherTypeNameToId($type);
        $classId = $this->matcherClassNameToId($class);

        $parameterDefaults = [
            'plugin_class_id' => $classId,
            'plugin_type_id' => $typeId,
            'name' => 'Test Matcher ' . $type,
            'description' => 'Test Matcher "' . $type . '" with class "' . $class . '"',
            'enabled' => 1,
            'weight' => $this->matcherWeight,
            'state' => '{}',
        ];

        $this->matcherWeight += self::MATCHER_WEIGHT_STEP;

        $mergedParameters = array_merge($parameterDefaults, $parameters);

        $matcher = $this->callAPISuccess('BankingPluginInstance', 'create', $mergedParameters);

        $configurationDefaults = [
            'auto_exec' => 1
        ];

        $mergedConfiguration = array_merge($configurationDefaults, $configuration);

        // Set the config via SQL (API causes issues):
        if (empty($matcher['id'])) {
            throw new Exception("Matcher could not be created.");
        } else {
            $configurationAsJson = json_encode($mergedConfiguration);

            CRM_Core_DAO::executeQuery(
                "UPDATE civicrm_bank_plugin_instance SET config=%1 WHERE id=%2;",
                [
                    1 => [$configurationAsJson, 'String'],
                    2 => [$matcher['id'], 'Integer']
                ]
            );
        }

        return $matcher['id'];
    }

    /**
     * Create a regex analyser with simple defaults.
     *
     * @param array|null $rules The rules to apply for the matcher. If null, default rules are used,
     *                          otherwise the given ones.
     * @param array $configuration The configuration for the matcher. Only set values will overwrite defaults.
     *
     * @return int The matcher ID.
     */
    protected function createRegexAnalyser(array $rules = null, array $configuration = []): int
    {
        $defaultRules = [
            [
                'comment' => 'Austrian address type 1',
                'fields' => [
                    'address_line'
                ],
                'pattern' => '#^(?P<postal_code>[0-9]{4}) (?P<city>[\\w\/]+)[ ,]*(?P<street_address>.*)$#',
                'actions' => [
                    [
                        'from' => 'street_address',
                        'action' => 'copy',
                        'to' => 'street_address'
                    ],
                    [
                        'from' => 'postal_code',
                        'action' => 'copy',
                        'to' => 'postal_code'
                    ],
                    [
                        'from' => 'city',
                        'action' => 'copy',
                        'to' => 'city'
                    ]
                ]
            ],
            [
                'comment' => 'Austrian address type 2',
                'fields' => [
                    'address_line'
                ],
                'pattern' => '#^(?P<street_address>[^,]+).*(?P<postal_code>[0-9]{4}) +(?P<city>[\\w ]+)$#',
                'actions' => [
                    [
                        'from' => 'street_address',
                        'action' => 'copy',
                        'to' => 'street_address'
                    ],
                    [
                        'from' => 'postal_code',
                        'action' => 'copy',
                        'to' => 'postal_code'
                    ],
                    [
                        'from' => 'city',
                        'action' => 'copy',
                        'to' => 'city'
                    ]
                ]
            ]
        ];

        $finalRules = $rules === null ? $defaultRules : $rules;

        $defaultConfiguration = [
            'rules' => $finalRules
        ];

        $mergedConfiguration = array_merge($defaultConfiguration, $configuration);

        $matcherId = $this->createMatcher('match', 'analyser_regex', $mergedConfiguration);

        return $matcherId;
    }

    /**
     * Create an ignore matcher with simple defaults.
     *
     * @param array|null $rules The rules to apply for the matcher. If null, default rules are used,
     *                          otherwise the given ones.
     * @param array $configuration The configuration for the matcher. Only set values will overwrite defaults.
     *
     * @return int The matcher ID.
     */
    protected function createIgnoreMatcher(array $rules = null, array $configuration = []): int
    {
        throw new NotImplementedException('TODO: Implement!');

        $defaultRules = [];

        $finalRules = $rules === null ? $defaultRules : $rules;

        $defaultConfiguration = [
            'rules' => $finalRules
        ];

        $mergedConfiguration = array_merge($defaultConfiguration, $configuration);

        $matcherId = $this->createMatcher('match', 'matcher_ignore', $mergedConfiguration);

        return $matcherId;
    }

    /**
     * Create a sepa matcher with simple defaults.
     *
     * @param array $configuration The configuration for the matcher. Only set values will overwrite defaults.
     *
     * @return int The matcher ID.
     */
    protected function createSepaMatcher(array $configuration = []): int
    {
        throw new NotImplementedException('TODO: Implement!');

        $defaultConfiguration = [];

        $mergedConfiguration = array_merge($defaultConfiguration, $configuration);

        $matcherId = $this->createMatcher('match', 'matcher_sepa', $mergedConfiguration);

        return $matcherId;
    }

    /**
     * Create a "default options matcher" with simple defaults.
     *
     * @param array $configuration The configuration for the matcher. Only set values will overwrite defaults.
     *
     * @return int The matcher ID.
     */
    protected function createDefaultOptionsMatcher(array $configuration = []): int
    {
        throw new NotImplementedException('TODO: Implement!');

        $defaultConfiguration = [];

        $mergedConfiguration = array_merge($defaultConfiguration, $configuration);

        $matcherId = $this->createMatcher('match', 'matcher_default', $mergedConfiguration);

        return $matcherId;
    }

    /**
     * Create a "create contribution" matcher with simple defaults.
     *
     * @param array $configuration The configuration for the matcher. Only set values will overwrite defaults.
     *
     * @return int The matcher ID.
     */
    protected function createCreateContributionMatcher(array $configuration = []): int
    {
        $defaultConfiguration = [
            'required_values' => [
                'btx.financial_type_id',
                'btx.payment_instrument_id',
                'btx.contact_id',
            ],
            'value_propagation' => [
                'btx.financial_type_id' => 'contribution.financial_type_id',
                'btx.payment_instrument_id' => 'contribution.payment_instrument_id',
            ],
            'lookup_contact_by_name' => [
                'mode' => 'off'
            ]
        ];

        $mergedConfiguration = array_merge($defaultConfiguration, $configuration);

        $matcherId = $this->createMatcher('match', 'matcher_create', $mergedConfiguration);

        return $matcherId;
    }

    /**
     * Create an "existing contribution matcher" with simple defaults.
     *
     * @param array $configuration The configuration for the matcher. Only set values will overwrite defaults.
     *
     * @return int The matcher ID.
     */
    protected function createExistingContributionMatcher(array $configuration = []): int
    {
        throw new NotImplementedException('TODO: Implement!');

        $defaultConfiguration = [];

        $mergedConfiguration = array_merge($defaultConfiguration, $configuration);

        $matcherId = $this->createMatcher('match', 'matcher_contribution', $mergedConfiguration);

        return $matcherId;
    }

    /**
     * Return the ID for a class by its name.
     *
     * @param string $className The name of the class.
     *
     * @return int The ID of the class.
     */
    protected function matcherClassNameToId(string $className): int
    {
        $result = $this->callAPISuccess(
            'OptionValue',
            'getsingle',
            [
                // NOTE: Class and type seem to be flipped in the extension code:
                'option_group_id' => 'civicrm_banking.plugin_types',
                'name' => $className,
            ]
        );

        return $result['id'];
    }

    /**
     * Return the ID for a type by its name.
     *
     * @param string $typeName The name of the type.
     *
     * @return int The ID of the type.
     */
    protected function matcherTypeNameToId(string $typeName): int
    {
        $result = $this->callAPISuccess(
            'OptionValue',
            'getsingle',
            [
                // NOTE: Class and type seem to be flipped in the extension code:
                'option_group_id' => 'civicrm_banking.plugin_classes',
                'name' => $typeName,
            ]
        );

        return $result['id'];
    }

    /**
     * Run matchers. By default, over all transactions created with "createTransaction" will be run.
     *
     * @param array|null $transactionIds Will be used instead of all created transactions if not null.
     */
    protected function runMatchers(array $transactionIds = null): void
    {
        $transactionIdsForMatching = $transactionIds === null ? $this->transactionIds : $transactionIds;

        $engine = CRM_Banking_Mocking_CRMBankingMatcherEngine::getInstance();

        foreach ($transactionIdsForMatching as $transactionId) {
            $engine->match($transactionId);
        }
    }
}

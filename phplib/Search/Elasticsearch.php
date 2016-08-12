<?php

namespace FOO;

/**
 * Class Elasticsearch_Search
 * Executes a query against an Elasticsearch cluster.
 * @package FOO
 */
abstract class Elasticsearch_Search extends Search {
    // Result types.
    /** Field data result type. */
    const R_FIELDS = 0;
    /** Count result type. */
    const R_COUNT = 1;
    /** No results result type. */
    const R_NO_RESULTS = 2;

    // Search type. Specify a unique string.
    public static $TYPE = 'elasticsearch';
    // Default config source.
    public static $CONFIG_NAME = '';

    /**
     * Return the configuration for this Search.
     * @return array Config data.
     */
    public static function getConfig() {
        static $config = null;
        if(is_null($config)) {
            /**
             * $config is an associative array with following values:
             *  src_url: Base source url. Used to generate source urls in the UI.
             *  hosts: Array of hosts in the elasticsearch cluster.
             *  index: Index to search. Optional.
             *  date_based: Whether this index is time-based. Auto-mangles index names if so.
             */
            $config = Config::get('elasticsearch')[static::$CONFIG_NAME];
        }

        return $config;
    }

    protected function _getLink(Alert $alert) {
        return $this->generateLink(
            Util::get($this->obj['query_data'], 'query'),
            $alert['alert_date'] - ($this->obj['range'] * 60),
            $alert['alert_date']
        );
    }

    public function generateLink($query, $start, $end) {
        $cfg = static::getConfig();
        if(is_null($cfg['src_url'])) {
            return null;
        }

        return sprintf($cfg['src_url'], $start, $end, rawurlencode($query));
    }

    public function isTimeBased() {
        return static::getConfig()['date_based'];
    }

    public function isWorking($date) {
        $cfg = static::getConfig();
        $client = ESClient::getClient(static::$CONFIG_NAME);

        $ret = false;
        try {
            if(is_null($cfg['index']) || !$this->isTimeBased()) {
                $client->cat()->health();
                $ret = true;
            } else {
                $dt = new \DateTime("@$date");
                $index = sprintf('%s-%s', $cfg['index'], $dt->format('Y.m.d'));
                $ret = $client->indices()->exists(['index' => $index]);
            }
        } catch(\Exception $e) {}
        return $ret;
    }

    protected function constructQuery() {
        $query = Util::get($this->obj['query_data'], 'query');
        $fields = Util::get($this->obj['query_data'], 'fields', []);
        $parser = new \ESQuery\Parser;
        list($settings, $query_list) = $parser->parse($query);

        $cfg = static::getConfig();

        if(count($cfg['hosts']) > 0) {
            $settings['host'] = $cfg['hosts'][array_rand($cfg['hosts'])];
        }
        if(!is_null($cfg['index'])) {
            $settings['index'] = $cfg['index'];
        }
        $settings['date_based'] = $cfg['date_based'];
        if(!is_null($cfg['date_field'])) {
            $event_time_based = Util::get($this->obj['query_data'], 'event_time_based', false);

            $settings['date_field'] = $cfg['date_field']; // F_DIS $event_time_based ? '@timestamp':'index_timestamp';
            // If a timestamp field was specified, make sure to always request it.
            if(count($fields)) {
                $fields[] = $cfg['date_field'];
            }
        }
        if(count($fields)) {
            $settings['fields'] = $fields;
        }
        return [
            $settings, $query_list, $fields, $cfg['date_field'],
            Util::get($this->obj['query_data'], 'result_type', 0),
            Util::get($this->obj['query_data'], 'filter_range', 0),
        ];
    }

    protected function _execute($date, $constructed_qdata) {
        list($settings, $query_list, $fields, $date_field, $result_type, $filter_range) = $constructed_qdata;

        // If our last_success_date is within 10 seconds of the start time, use that
        // as the start time.
        $from = $date - ($this->obj['range'] * 60);
        if(abs($this->obj['last_success_date'] - $from) < 10) {
            $from = $this->obj['last_success_date'];
        }
        $settings['from'] = $from;
        $settings['to'] = $date;
        // Somewhat arbitrary size. 500 per day.
        if(!array_key_exists('size', $settings)) {
            $settings['size'] = (floor($this->obj['range'] / 1440) + 1) * 500;
        }

        return $this->search($date,
            $settings, $query_list,
            $fields, $date_field,
            $result_type, $filter_range
        );
    }

    public function getList($name) {
        return Util::get($this->getListData([$name]), $name, []);
    }

    /**
     * Execute an Elasticsearch query and return the results.
     * @param int $date What time this query was started.
     * @param string $settings The query settings.
     * @param array $query_list The query list.
     * @param array $fields A list of fields to include.
     * @param string $date_field The date field to pull the date from.
     * @param int $result_type The type of result to return.
     * @param array[] $filter_range The lower and upper bounds for results. Use null to represent an unbounded side.
     * @return Alert[] A list of Alert results.
     * @throws SearchException
     */
    public function search($date, $settings, $query_list, $fields, $date_field, $result_type, $filter_range) {
        // If we're looking for no results, set filter to (< 1)
        if($result_type == self::R_NO_RESULTS) {
            $filter_range = [0, 0];
        }

        // If we got a malformed filter range, overwrite.
        if(count($filter_range) != 2) {
            $filter_range = [null, null];
        }

        $alerts = [];
        try {
            // Grab a count of results.
            $count_settings = $settings;
            $count_settings['count'] = true;
            $es = new \ESQuery\Scheduler($count_settings, $query_list, null, [$this, 'getList']);
            $count = $es->execute()['count'];

            // Determine whether continue processing.
            $ok = true;
            if(!is_null($filter_range[0]) && $ok) {
                $ok = $count >= $filter_range[0];
            }
            if(!is_null($filter_range[1]) && $ok) {
                $ok = $count <= $filter_range[1];
            }

            // _index, _type, _id and _score always show up. If they aren't explicitly included in the field list, just exclude them!
            $underscore_fields = ['_index', '_type', '_id', '_score'];
            $field_specified = [];
            foreach($underscore_fields as $field) {
                if(in_array($field, $fields)) {
                    $field_specified[$field] = null;
                }
            }

            if($ok) {
                switch($result_type) {
                    // Rows
                    case self::R_FIELDS:
                        $es = new \ESQuery\Scheduler($settings, $query_list, null, [$this, 'getList']);
                        $data = $es->execute();

                        foreach($data as $row) {
                            $alert = new Alert;
                            if (!array_key_exists('time', $row)) {
                                $alert_date = $date;
                                if (array_key_exists($date_field, $row)) {
                                    // Extract the date field.
                                    if(ctype_digit($row[$date_field])) {
                                        $alert_date = (int) $row[$date_field];
                                    } else {
                                        $alert_date = strtotime($row[$date_field]);
                                    }
                                    unset($row[$date_field]);
                                }
                                $alert['alert_date'] = $alert_date;
                            }
                            foreach($underscore_fields as $field) {
                                if(!array_key_exists($field, $field_specified)) {
                                    unset($row[$field]);
                                }
                            }
                            $alert['content'] = $row;
                            $alerts[] = $alert;
                        }
                        break;

                    // Count
                    case self::R_COUNT:
                        $alert = new Alert;
                        $alert['alert_date'] = $date;
                        $alert['content'] = ['count' => $count];
                        $alerts[] = $alert;
                        break;

                    // No data
                    case self::R_NO_RESULTS:
                        $alert = new Alert;
                        $alert['alert_date'] = $date;
                        $alerts[] = $alert;
                        break;
                }
            }
        } catch(\Exception $e) {
            throw new SearchException($e->getMessage());
        }

        return $alerts;
    }
}

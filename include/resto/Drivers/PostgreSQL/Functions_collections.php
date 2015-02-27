<?php

/*
 * RESTo
 * 
 * RESTo - REstful Semantic search Tool for geOspatial 
 * 
 * Copyright 2013 Jérôme Gasperi <https://github.com/jjrom>
 * 
 * jerome[dot]gasperi[at]gmail[dot]com
 * 
 * 
 * This software is governed by the CeCILL-B license under French law and
 * abiding by the rules of distribution of free software.  You can  use,
 * modify and/ or redistribute the software under the terms of the CeCILL-B
 * license as circulated by CEA, CNRS and INRIA at the following URL
 * "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and  rights to copy,
 * modify and redistribute granted by the license, users are provided only
 * with a limited warranty  and the software's author,  the holder of the
 * economic rights,  and the successive licensors  have only  limited
 * liability.
 *
 * In this respect, the user's attention is drawn to the risks associated
 * with loading,  using,  modifying and/or developing or reproducing the
 * software by the user in light of its specific status of free software,
 * that may mean  that it is complicated to manipulate,  and  that  also
 * therefore means  that it is reserved for developers  and  experienced
 * professionals having in-depth computer knowledge. Users are therefore
 * encouraged to load and test the software's suitability as regards their
 * requirements in conditions enabling the security of their systems and/or
 * data to be ensured and,  more generally, to use and operate it in the
 * same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL-B license and that you accept its terms.
 * 
 */

/**
 * RESTo PostgreSQL collections functions
 */
class Functions_collections {
    
    private $dbDriver = null;
    private $dbh = null;
    
    /**
     * Constructor
     * 
     * @param array $config
     * @param RestoCache $cache
     * @throws Exception
     */
    public function __construct($dbDriver) {
        $this->dbDriver = $dbDriver;
        $this->dbh = $dbDriver->getHandler();
    }

    /**
     * List all collections
     * 
     * @return array
     * @throws Exception
     */
    public function getCollections() {
        $query = 'SELECT collection FROM resto.collections';
        return $this->dbDriver->fetch($this->dbDriver->query($query));
    }
    
    /**
     * Get description of all collections including facets
     * 
     * @param array $facetFields
     * @return array
     * @throws Exception
     */
    public function getCollectionsDescriptions($collectionName = null, $facetFields = null) {
         
        $cached = $this->dbDriver->cache->retrieve(array('getCollectionsDescriptions', $facetFields));
        if (isset($cached)) {
            return $cached;
        }
        
        $collectionsDescriptions = array();
        $descriptions = $this->dbDriver->query('SELECT collection, status, model, mapping, license FROM resto.collections' . (isset($collectionName) ? ' WHERE collection=\'' . pg_escape_string($collectionName) . '\'' : ''));
        while ($collection = pg_fetch_assoc($descriptions)) {
            $collectionsDescriptions[$collection['collection']]['model'] = $collection['model'];
            $collectionsDescriptions[$collection['collection']]['osDescription'] = array();
            $collectionsDescriptions[$collection['collection']]['status'] = $collection['status'];
            $collectionsDescriptions[$collection['collection']]['propertiesMapping'] = json_decode($collection['mapping'], true);
            $collectionsDescriptions[$collection['collection']]['license'] = isset($collection['license']) ? json_decode($collection['license'], true) : null;

            /*
             * Get OpenSearch descriptions
             */
            $results = pg_query($this->dbh, 'SELECT * FROM resto.osdescriptions WHERE collection = \'' . pg_escape_string($collection['collection']) . '\'');
            while ($description = pg_fetch_assoc($results)) {
                $collectionsDescriptions[$collection['collection']]['osDescription'][$description['lang']] = array(
                    'ShortName' => $description['shortname'],
                    'LongName' => $description['longname'],
                    'Description' => $description['description'],
                    'Tags' => $description['tags'],
                    'Developper' => $description['developper'],
                    'Contact' => $description['contact'],
                    'Query' => $description['query'],
                    'Attribution' => $description['attribution']
                );
            }

            /*
             * Get Facets
             */
            if (isset($facetFields)) {
                $collectionsDescriptions[$collection['collection']]['statistics'] = $this->dbDriver->get(RestoDatabaseDriver::STATISTICS, array('collectionName' => $collection['collection'], 'facetFields' => $facetFields));
            }

        }
        
        /*
         * Store in cache
         */
        $this->dbDriver->cache->store(array('getCollectionsDescriptions', $facetFields), $collectionsDescriptions);
        
        return isset($collectionName) ? $collectionsDescriptions[$collectionName] : $collectionsDescriptions;
        
    }
    
    /**
     * Check if collection $name exists within resto database
     * 
     * @param string $name - collection name
     * @return boolean
     * @throws Exception
     */
    public function collectionExists($name) {
        $query = 'SELECT collection FROM resto.collections WHERE collection=\'' . pg_escape_string($name) . '\'';
        return !$this->dbDriver->isEmpty($this->dbDriver->fetch($this->dbDriver->query($query)));
    }
    
    /**
     * Remove collection from RESTo database
     * 
     * @param RestoCollection $collection
     * @return array
     * @throws Exception
     */
    public function removeCollection($collection) {
        
        $results = $this->dbDriver->query('SELECT collection FROM resto.collections WHERE collection=\'' . pg_escape_string($collection->name) . '\'');
        $schemaName = $this->dbDriver->getSchemaName($collection->name);
        
        if (pg_fetch_assoc($results)) {
                
            /*
             * Delete (within transaction)
             *  - entry within osdescriptions table
             *  - entry within collections table
             */
            $query = 'BEGIN;';
            $query .= 'DELETE FROM resto.osdescriptions WHERE collection=\'' . pg_escape_string($collection->name) . '\';';
            $query .= 'DELETE FROM resto.collections WHERE collection=\'' . pg_escape_string($collection->name) . '\';';
            
            /*
             * Do not drop schema if product table is not empty
             */
            if ($this->dbDriver->is(RestoDatabaseDriver::SCHEMA, array('name' => $schemaName)) && $this->dbDriver->is(RestoDatabaseDriver::TABLE_EMPTY, array('name' => 'features', 'schema' => $schemaName))) {
                $query .= 'DROP SCHEMA ' . $schemaName . ' CASCADE;';
            }

            $query .= 'COMMIT;';
            $this->dbDriver->query($query);
            /*
             * Rollback on error
             */
            if ($this->collectionExists($collection->name)) {
                pg_query($this->dbh, 'ROLLBACK');
                RestoLogUtil::httpError(500, 'Cannot delete collection ' . $collection->name);
            }
        }
        
    }
    
    /**
     * Save collection to database
     * 
     * @param RestoCollection $collection
     * @throws Exception
     */
    public function storeCollection($collection) {
        
        $schemaName = $this->dbDriver->getSchemaName($collection->name);
        
        try {
            
            /*
             * Prepare one column for each key entry in model
             */
            $table = array();
            foreach (array_keys($collection->model->extendedProperties) as $key) {
                if (is_array($collection->model->extendedProperties[$key])) {
                    if (isset($collection->model->extendedProperties[$key]['name']) && isset($collection->model->extendedProperties[$key]['type'])) {
                        $table[] = $collection->model->extendedProperties[$key]['name'] . ' ' . $collection->model->extendedProperties[$key]['type'] . (isset($collection->model->extendedProperties[$key]['constraint']) ? ' ' . $collection->model->extendedProperties[$key]['constraint'] : '');
                    }
                }
            }

            /*
             * Start transaction
             */
            pg_query($this->dbh, 'BEGIN');

            /*
             * Create schema if needed
             */
            if (!$this->dbDriver->is(RestoDatabaseDriver::SCHEMA, array('name' => $schemaName))) {
                pg_query($this->dbh, 'CREATE SCHEMA ' . $schemaName);
                pg_query($this->dbh, 'GRANT ALL ON SCHEMA ' . $schemaName . ' TO resto');
            }
            /*
             * Create schema.features if needed with a CHECK on collection name
             */
            if (!$this->dbDriver->is(RestoDatabaseDriver::TABLE, array('name' => 'features', 'schema' => $schemaName))) {
                pg_query($this->dbh, 'CREATE TABLE ' . $schemaName . '.features (' . (count($table) > 0 ? join(',', $table) . ',' : '') . 'CHECK( collection = \'' . $collection->name . '\')) INHERITS (resto.features);');
                $indices = array(
                    'identifier' => 'btree',
                    'visible' => 'btree',
                    'platform' => 'btree',
                    'resolution' => 'btree',
                    'startDate' => 'btree',
                    'completionDate' => 'btree',
                    'cultivatedCover' => 'btree',
                    'desertCover' => 'btree',
                    'floodedCover' => 'btree',
                    'forestCover' => 'btree',
                    'herbaceousCover' => 'btree',
                    'iceCover' => 'btree',
                    'snowCover' => 'btree',
                    'urbanCover' => 'btree',
                    'waterCover' => 'btree',
                    'cloudCover' => 'btree',
                    'geometry' => 'gist',
                    'hashes' => 'gin'
                );
                foreach ($indices as $key => $indexType) {
                    if (!empty($key)) {
                        pg_query($this->dbh, 'CREATE INDEX ' . $schemaName . '_features_' . $collection->model->getDbKey($key) . '_idx ON ' . $schemaName . '.features USING ' . $indexType . ' (' . $collection->model->getDbKey($key) . ($key === 'startDate' || $key === 'completionDate' ? ' DESC)' : ')'));
                    }
                }
                pg_query($this->dbh, 'GRANT SELECT ON TABLE ' . $schemaName . '.features TO resto');
            }


            /*
             * Insert collection within collections table
             * 
             * CREATE TABLE resto.collections (
             *  collection          TEXT PRIMARY KEY,
             *  creationdate        TIMESTAMP,
             *  model               TEXT DEFAULT 'Default',
             *  status              TEXT DEFAULT 'public',
             *  license             TEXT,
             *  mapping             TEXT
             * );
             * 
             */
            $license = isset($collection->license) && count($collection->license) > 0 ? '\'' . pg_escape_string(json_encode($collection->license)) . '\'' : 'NULL';
            if (!$this->collectionExists($collection->name)) {
                pg_query($this->dbh, 'INSERT INTO resto.collections (collection, creationdate, model, status, license, mapping) VALUES(' . join(',', array('\'' . pg_escape_string($collection->name) . '\'', 'now()', '\'' . pg_escape_string($collection->model->name) . '\'', '\'' . pg_escape_string($collection->status) . '\'', $license, '\'' . pg_escape_string(json_encode($collection->propertiesMapping)) . '\'')) . ')');
            }
            else {
                pg_query($this->dbh, 'UPDATE resto.collections SET status = \'' . pg_escape_string($collection->status) . '\', mapping = \'' . pg_escape_string(json_encode($collection->propertiesMapping)) . '\', license=' . $license . ' WHERE collection = \'' . pg_escape_string($collection->name) . '\'');
            }

            /*
             * Insert OpenSearch descriptions within osdescriptions table
             * 
             * CREATE TABLE resto.osdescriptions (
             *  collection          VARCHAR(50),
             *  lang                VARCHAR(2),
             *  shortname           VARCHAR(50),
             *  longname            VARCHAR(255),
             *  description         TEXT,
             *  tags                TEXT,
             *  developper          VARCHAR(50),
             *  contact             VARCHAR(50),
             *  query               VARCHAR(255),
             *  attribution         VARCHAR(255)
             * );
             */
            pg_query($this->dbh, 'DELETE FROM resto.osdescriptions WHERE collection=\'' . pg_escape_string($collection->name) . '\'');

            /*
             * Insert one description per lang
             */
            foreach ($collection->osDescription as $lang => $description) {
                $osFields = array(
                    'collection',
                    'lang'
                );
                $osValues = array(
                    '\'' . pg_escape_string($collection->name) . '\'',
                    '\'' . pg_escape_string($lang) . '\''
                );
                foreach (array_keys($description) as $key) {
                    $osFields[] = strtolower($key);
                    $osValues[] = '\'' . pg_escape_string($description[$key]) . '\'';
                }
                pg_query($this->dbh, 'INSERT INTO resto.osdescriptions (' . join(',', $osFields) . ') VALUES(' . join(',', $osValues) . ')');
            }

            /*
             * Close transaction
             */
            pg_query($this->dbh, 'COMMIT');

            /*
             * Rollback on errors
             */
            if (!$this->dbDriver->is(RestoDatabaseDriver::SCHEMA, array('name' => $schemaName))) {
                pg_query($this->dbh, 'ROLLBACK');
                RestoLogUtil::httpError(2000);
            }
            if (!$this->collectionExists($collection->name)) {
                pg_query($this->dbh, 'ROLLBACK');
                RestoLogUtil::httpError(2000);
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode());
        }
    }
    
}
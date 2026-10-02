#!/usr/bin/env node
/**
 * The map build's personKey() (scripts/build-network-graph.js) for each name
 * in a JSON list: node scripts/person-key.js names.json -> JSON list of keys.
 * scripts/test-people.php compares them with kop_people_key().
 */
const fs = require('fs');
const { personKey } = require('./build-network-graph.js');
const names = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
process.stdout.write(JSON.stringify(names.map(personKey)));

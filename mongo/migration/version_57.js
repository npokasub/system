/* 
 * Version 5.7 Idempotent DB migration script goes here.
 * Please try to avoid using migration script and instead make special treatment in the code!
 */


// BRCD-1002- Add weight property type to units of measure
var lastConfig = db.config.find().sort({_id: -1}).limit(1).pretty()[0];
if (lastConfig.property_types && lastConfig.property_types.filter(element => element.type === 'weight').length == 0) {
	delete lastConfig['_id'];
	lastConfig.property_types.push(
			{
				"system": true,
				"type": "weight",
				"uom": [{"unit": 1, "name": "mg", "label": "mg"}, {"unit": 1000, "name": "g", "label": "g"}, {"unit": 1000000, "name": "kg", "label": "kg"}, {"unit": 1000000000, "name": "ton", "label": "ton"}],
				"invoice_uom": "kg"
			});
	db.config.insert(lastConfig);
}

// BRCD-1078: add rate categories
var lastConfig = db.config.find().sort({_id: -1}).limit(1).pretty()[0];
delete lastConfig['_id'];
for (var i in lastConfig['file_types']) {
	var firstKey = Object.keys(lastConfig['file_types'][i]['rate_calculators'])[0];
	var secKey = Object.keys(lastConfig['file_types'][i]['rate_calculators'][firstKey])[0];
	if (secKey == 0) {
		lastConfig['file_types'][i]['rate_calculators']['retail'] = {};
		for (var usaget in lastConfig['file_types'][i]['rate_calculators']) {
			if (usaget === 'retail') {
				continue;
			}
			lastConfig['file_types'][i]['rate_calculators']['retail'][usaget] = lastConfig['file_types'][i]['rate_calculators'][usaget];
			delete lastConfig['file_types'][i]['rate_calculators'][usaget];
		}
	}
}
db.config.insert(lastConfig);

// BRCD-988 - rating priorities
var lastConfig = db.config.find().sort({_id: -1}).limit(1).pretty()[0];
delete lastConfig['_id'];
for (var i in lastConfig['file_types']) {
	for (var rateCat in lastConfig['file_types'][i]['rate_calculators']) {
		for (var usaget in lastConfig['file_types'][i]['rate_calculators'][rateCat]) {
			if (typeof lastConfig['file_types'][i]['rate_calculators'][rateCat][usaget][0][0] === 'undefined') {
				lastConfig['file_types'][i]['rate_calculators'][rateCat][usaget] = [lastConfig['file_types'][i]['rate_calculators'][rateCat][usaget]];
			}
		}
	}
}
db.config.insert(lastConfig);

// BRCD-865 - extend postpaid balances period
db.balances.update({"period":{$exists:0}},{"$set":{"period":"default","start_period":"default"}}, {multi:1});


// BRCD-811 - Save process_time field as a date instead of a string
db.lines.find({"process_time":{$exists:1}}).forEach( function(line) { 
	if (typeof line.process_time == 'string'){
		db.lines.update({_id:line._id},{$set:{process_time:new ISODate(line.process_time)}})
	}
});
db.log.find({"process_time":{$exists:1}}).forEach( function(line) { 
	if (typeof line.process_time == 'string'){
		db.log.update({_id:line._id},{$set:{process_time:new ISODate(line.process_time)}})
	}
});

// BRCD-986 - fix empty "computed" field
var lastConfig = db.config.find().sort({_id: -1}).limit(1).pretty()[0];
delete lastConfig['_id'];
for (var i in lastConfig['file_types']) {
	for (var usaget in lastConfig['file_types'][i]['rate_calculators']) {
		for (var priority in lastConfig['file_types'][i]['rate_calculators'][usaget]) {
			for (var j in lastConfig['file_types'][i]['rate_calculators'][usaget][priority]) {
				if (lastConfig['file_types'][i]['rate_calculators'][usaget][priority][j].computed && lastConfig['file_types'][i]['rate_calculators'][usaget][priority][j].computed.length === 0) {
					delete lastConfig['file_types'][i]['rate_calculators'][usaget][priority][j].computed;
				}
			}
		}
	}
}
db.config.insert(lastConfig);

// BRCD-865 - overlapping extend balances services
db.balances.update({"priority":{$exists:0}},{"$set":{"priority":0}}, {multi:1});

db.createCollection('prepaidgroups');
db.prepaidgroups.ensureIndex({ 'name':1, 'from': 1, 'to': 1 }, { unique: false, background: true });
db.prepaidgroups.ensureIndex({ 'name':1, 'to': 1 }, { unique: false, sparse: true, background: true });
db.prepaidgroups.ensureIndex({ 'description': 1}, { unique: false, background: true });


// BRCD-1143 - Input Processors fields new strucrure
var lastConfig = db.config.find().sort({_id: -1}).limit(1).pretty()[0];
delete lastConfig['_id'];
for (var i in lastConfig['file_types']) {
	if(["fixed"].includes(lastConfig['file_types'][i]['parser']['type'])){
		if (!Array.isArray(lastConfig['file_types'][i]['parser']['structure'])) {
			var newStructure = [];
			for (var name in lastConfig['file_types'][i]['parser']['structure']) {
				newStructure.push({
					name: name,
          width:  lastConfig['file_types'][i]['parser']['structure'][name]
				});
			}
			lastConfig['file_types'][i]['parser']['structure'] = newStructure;
		}
	} else if(typeof lastConfig['file_types'][i]['parser']['structure'][0] === 'string'){
			var newStructure = [];
			for (var j in lastConfig['file_types'][i]['parser']['structure']) {
				newStructure.push({
					name: lastConfig['file_types'][i]['parser']['structure'][j],
				});
			}
			lastConfig['file_types'][i]['parser']['structure'] = newStructure;
	}
}
db.config.insert(lastConfig);

// BRCD-1114: change customer mapping structure
var lastConfig = db.config.find().sort({_id: -1}).limit(1).pretty()[0];
delete lastConfig['_id'];
for (var i in lastConfig['file_types']) {
	var mappings = {};
	var firstKey = Object.keys(lastConfig['file_types'][i]['customer_identification_fields'])[0];
	if (firstKey != 0) {
		continue;
	}
	for (var priority in lastConfig['file_types'][i]['customer_identification_fields']) {
		var regex = lastConfig['file_types'][i]['customer_identification_fields'][priority]['conditions'][0]['regex'];
		var data = lastConfig['file_types'][i]['customer_identification_fields'][priority];
		delete data['conditions'];
		var usaget = regex.substring(2, regex.length - 2);;
		if (!mappings[usaget]) {
			mappings[usaget] = [];
		}
		mappings[usaget].push(data);
	}
	lastConfig['file_types'][i]['customer_identification_fields'] = mappings;
}
db.config.insert(lastConfig);

// BRCD-865 - overlapping extend balances services
db.balances.update({"priority":{$exists:0}},{"$set":{"priority":0}}, {multi:1});

// BRCD-908 - Rebalance field changes
db.lines.find({"rebalance":{$exists:1}}).forEach( function(line) { 
	if (!Array.isArray(line.rebalance)){
		db.lines.update({_id:line._id},{$set:{rebalance:[line.rebalance]}})
	}
});

// BRCD-1044: separate volume field to different usage types
var lastConfig = db.config.find().sort({_id: -1}).limit(1).pretty()[0];
delete lastConfig['_id'];
for (var i in lastConfig['file_types']) {
	var volumeFields = lastConfig['file_types'][i]['processor']['volume_field'];
	if (typeof volumeFields  === 'undefined') {
		continue;
	}
	if (typeof lastConfig['file_types'][i]['processor']['usaget_mapping'] !== 'undefined') {
		for (var j in lastConfig['file_types'][i]['processor']['usaget_mapping']) {
			lastConfig['file_types'][i]['processor']['usaget_mapping'][j]['volume_type'] = 'field';
			lastConfig['file_types'][i]['processor']['usaget_mapping'][j]['volume_src'] = volumeFields;
		}
	} else {
		lastConfig['file_types'][i]['processor']['default_volume_type'] = 'field';
		lastConfig['file_types'][i]['processor']['default_volume_src'] = volumeFields;
	}
	delete lastConfig['file_types'][i]['processor']['volume_field'];
}
db.config.insert(lastConfig);

// BRCD-1164 - Don't set balance_period field when it's irrelevant
db.services.update({balance_period:"default"},{$unset:{balance_period:1}},{multi:1})
<<<<<<< HEAD


// BRCD-1077 Add new custom 'tariff_category' field to Products(Rates).
var lastConfig = db.config.find().sort({_id: -1}).limit(1).pretty()[0];
delete lastConfig['_id'];
var fields = lastConfig['rates']['fields'];
var found = false;
for (var field_key in fields) {
	if (fields[field_key].field_name === "tariff_category") {
		found = true;
	}
}
if(!found) {
	fields.push({
		"system":true,
		"select_list":true,
		"display":true,
		"editable":true,
		"field_name":"tariff_category",
		"default_value":"retail",
		"show_in_list":true,
		"title":"Tariff category",
		"mandatory":true,
		"select_options":"retail",
		"changeable_props": ["select_options"]
	});
}
lastConfig['rates']['fields'] = fields;
db.config.insert(lastConfig);
// BRCD-1077 update all products(Rates) tariff_category field.
db.rates.find().forEach(function (rate) {
	if (!rate.hasOwnProperty("tariff_category")) {
		rate.tariff_category = "retail";
	}
	db.rates.save(rate);
});
=======
>>>>>>> 0082a0125e835cd837e95a2a0b5d08827cbf0295

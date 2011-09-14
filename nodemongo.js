// connecting to mongodb from node, and getting some data

var mongo = require("mongodb");
var db = new mongo.Db("robotag", new mongo.Server("localhost",27017,{}), {});
var currentDoc;

db.open(function(err, db) {
	db.collection("users", function(err, collection) {
		collection.find(function(err, cursor) {
			cursor.nextObject(function(err, doc) {
				currentDoc = doc;
				console.log(typeof(currentDoc));
				console.log(currentDoc);
				
			});
		});
	});
	db.close();
});


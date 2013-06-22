var artist = process.argv[2] && process.argv[2].replace(/\s+/g, "_");
var track = process.argv[3] && process.argv[3].replace(/\s+/g, "_");
if(!artist || !track) {
	console.log("Usage: node lyrics_scrape.js [artist] [track]");
	process.exit(1);
}

var url = "http://lyrics.wikia.com/" + artist + ":" + track;
var request = require("request");
var cheerio = require("cheerio");

var start = new Date();
console.log("Fetching lyrics from " + url);
request(url, function(err, response, html) {
	if(err) return console.error(err);
	var dl = new Date();
	console.log("Fetch completed in " + (dl-start) + " ms");
	var $ = cheerio.load(html);
	$("div.lyricbox > .rtMatcher, div.lyricbox > .lyricsbreak").remove();
	$("div.lyricbox > br").replaceWith("\n");
	var lyrics = $("div.lyricbox").text();
	var done = new Date();
	console.log("Lyrics scrape completed in " + (done-start) + " ms");
	console.log(lyrics.split("\n"));
	process.exit(0);
});
/*
var jsdom = require("jsdom");
var start = new Date();
console.log("Fetching lyrics from " + url);
jsdom.env( url, ["http://code.jquery.com/jquery.min.js"],
	function (errors, window) {
		var dl = new Date();
		console.log("Fetch completed in " + (dl-start) + " ms");
		var lyrics = window.$("div.lyricbox").contents()
			.filter(function() {
				return (this.nodeType == 3 && this.nodeValue.search(/^\s+$/) < 0) || (this.nodeName.toLowerCase() == "br");
			})
			.map(function() {
				if(this.nodeType == 3)
					return this.nodeValue;
				else
					return "";
			})
			.toArray()
			.filter(function(val, i, arr) {
				return val !== "" || arr[i+1] === "";
			});
		console.log(lyrics);
		var done = new Date();
		console.log("Lyrics scrape completed in " + (done-start) + " ms");
		process.exit(0);
	}
);
*/

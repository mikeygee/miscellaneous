// lyric wiki jquery scraper [http://lyrics.wikia.com]

var song = {};
var header = $("#WikiaPageHeader h1").html().split(":");
song.artist = header[0];
song.title = header[1];
var lyricsDiv = $(".lyricbox").html().replace(/<i>|<\/i>/g,"");
song.lyrics = lyricsDiv.substring(lyricsDiv.indexOf("</div>")+6, lyricsDiv.indexOf("<!")).split("<br>");


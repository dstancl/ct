iVysilani (Czech television archive) downloader
===============================================

Downloads video from Czech television archive named [http://www.ivysilani.cz/] iVysilani.

Scripts description
-------------------

`getct.sh`

Wrapper for `getct.php`. Requires `getct.php` located at `$HOME/bin`.

`getct.php`

PHP script for downloading. Usage:

```
php -f getct.php -- [-o filename] [-a] [-v] [-vv] [-f] [-s] [-d] [-l] [-q quality] [-b begin_time] [-e end_time] [-h] url
```

where

* `-o filename` output to filename (default: `video.mp4` or video title)
* `-v` be verbose (show what will be done)
* `-vv` set verbose mode for downloader
* `-f` use `ffmpeg` in VLC mode
* `-s` "dry" mode (don't run downloader)
* `-d` debug mode (show debugging informations)
* `-l` live (in RTMP mode)
* `-q quality` setup quality (number)
* `-b begin_time` begin at (in RTMP mode, `-l` overrides this)
* `-e end_time` stop at (in RTMP mode, `-l` overrides this)
* `-h` show this help
* `-a` article mode
* `url` video URL or article id string to be downloaded

Modes
-----

* RTMP mode: uses `rtmpdump` for downloading, before 2015;
* VLC mode: uses `cvlc` for downloading, used in year 2015;
* in VLC mode is possible use `ffmpeg` for downloading.

`ctdown-all.sh`

Uses `ctdown.lst` file for downloading more videos. File format:

```
filename url
filename url
...
```

Filename is without extension (`.mp4` added), `url` is video URL.

Script defaults (where store downloaded videos, temporary directory etc.) can be overrided by file `ctdown.cfg` in `$HOME/.config` directory.



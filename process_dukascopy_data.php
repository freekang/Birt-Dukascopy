<?php
/*
    Copyright (C) 2009-2011 Cristi Dumitrescu <birt@eareview.net>
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.

    Version: 0.27
*/
if ($argc != 5) {
    echo("Syntax: ".__FILE__." CURRENCY_PAIR START_DATE END_DATE OUT_FILE\nSTART_DATE and END_DATE must be of the form YYYYMM\nExample: ".__FILE__." EURUSD 200702 200908 out.csv\n");
    exit(1);
}
$pair = $argv[1];
if (!file_exists($pair)) {
    echo "Error: $pair folder does not exist\n";
    exit(1);
}
if (strlen($argv[2]) != 6 || strlen($argv[3]) != 6) {
    echo "The input dates must be of the form YYYYMM, example 200808\n";
    exit(1);
}
$extract = '';
$iswindows = false;
if (stripos(PHP_OS, 'win') === false || stripos(PHP_OS, 'darwin') !== false) {
    exec('lzma -h 2>/dev/null', $output);
    if (count($output) > 0) {
        $extract = 'lzma -kdc -S bi5 %s';
    }
    else {
        exec('xz -h 2>/dev/null', $output);
        if (count($output) > 0) {
            $extract = 'xz -dc %s';
        }
    }
}
else {
    $iswindows = true;
    exec('7za 2>NUL', $output);
    if (count($output) > 0) {
        $extract = '7za e -o"%s" %s';
    }

}
if (strlen($extract) == 0) {
    echo "!!!!!!!!!!!!!!!!!!!! WARNING !!!!!!!!!!!!!!!!!!!!\n";
    echo "!! There was no program found able to handle LZMA archives, so bi5 files will not be processed !!\n";
    echo "To install such a program:\n";
    echo "On Debian-based systems (Ubuntu, Knoppix etc) type: sudo apt-get install lzma\n";
    echo "On Redhat-based systems (CentOS, Fedora etc) type: yum install xz\n";
    echo "On Windows, download the command line version of 7-Zip from http://www.7-zip.org/download.html and unpack 7za.exe in the folder with this script\n";
    echo "On Mac & FreeBSD, you have to install lzma from ports, if you don't know how, use google.\n";
    echo "Press CTRL+C to stop this script or hit Enter to proceed and process the available bin files.\n";
    while ($c = fread(STDIN, 1)) {
        if ($c == "\n") {
            break;
        }
    }
}

$point = 0.00001;
if (stripos($pair, 'jpy') !== false ||
    strcasecmp($pair, 'usdrub') == 0 ||
    strcasecmp($pair, 'xagusd') == 0 ||
    strcasecmp($pair, 'xauusd') == 0) {
    $point = 0.001;
}
else if (stripos($pair, 'rub') !== false) {
    $point = 0.001;
}


$outfile = $argv[4];
$startyear = substr($argv[2],0,4);
$startmonth = substr($argv[2],4,2);
$endyear = substr($argv[3],0,4);
$endmonth = substr($argv[3],4,2);
if ($startyear < 1900 || $startyear > 2100 || $endyear < 1900 || $endyear > 2100 || $startmonth < 1 || $startmonth > 12 || $endmonth < 1 || $endmonth > 12) {
    echo "The input dates must be of the form YYYYMM, example 200808\n";
    exit(1);
}
$outfd = fopen($outfile,'a+');
if ($outfd === FALSE) {
    echo "Cannot open $outfile for writing.\n";
    exit(1);
}
$starttime = gmmktime(0,0,0,$startmonth,1,$startyear);
$endtime = gmmktime(0,0,0,$endmonth,1,$endyear);
print "Processing data starting from ".gmstrftime("%m/%d/%y %H:%M:%S",$starttime)." up to ".gmstrftime("%m/%d/%y %H:%M:%S",$endtime)."\n";
$starttime -= $starttime % 3600;
$tmpdir = tempnam(sys_get_temp_dir(), "tickdata-");
unlink($tmpdir);
mkdir($tmpdir);
for($i = $starttime; $i < $endtime; $i += 3600) {
    $year = gmstrftime('%Y',$i);
    $month = str_pad(gmstrftime('%m',$i) - 1, 2, '0', STR_PAD_LEFT);
    $day = gmstrftime('%d',$i);
    $hour = gmstrftime('%H',$i);
    $localpath = "$pair/$year/$month/$day/";
    $binlocalfile = $localpath . $hour . "h_ticks.bin";
    $localfile = $localpath . $hour . "h_ticks.bi5";
    $bin = false;
    if (!file_exists($localfile)) {
        if (!file_exists($binlocalfile)) {
            echo "Error: can't find $localfile or $binlocalfile\n";
            continue;
        }
        else {
            $localfile = $binlocalfile;
            $bin = true;
        }
    }
    if (filesize($localfile) > 0) {
        if ($bin) {
            decode_ducascopy_bin($localfile, $outfd);
        }
        else {
            decode_ducascopy_bi5($localfile, $outfd, $i);
        }
    }
    else {
        echo "Warning: 0 sized $localfile\n";
    }
}
rmdir($tmpdir);
fclose($outfd);

function decode_ducascopy_bin($fname, $outfd) {
    print "$fname\n";
    $zip = new ZipArchive;
    $res = $zip->open($fname);
    if ($res!==true) {
        echo "Error: failed to open [$fname] code [$res]\n";
        exit(1);
    }
    $binname = $zip->getNameIndex(0);
    global $tmpdir;
    $res = $zip->extractTo($tmpdir, $binname);
    if (!$res) {
        echo "Error: unable to extract from zip archive\n";
        exit(1);
    }
    $bin = file_get_contents($tmpdir.'/'.$binname);
    if (strlen($bin) == 0) {
        echo "Error: unable to read extracted file\n";
        exit(1);
    }
    unlink($tmpdir.'/'.$binname);
    $idx = 0;
    $size = strlen($bin);
    while($idx < $size) {
        //print "$idx $size\n";
        $q = unpack('@'.$idx.'/n4', $bin);
        $time = bcmul('4294967296', bcadd($q['2'],bcmul($q['1'],65536)));
        $time = bcadd($time, bcadd($q['4'],bcmul($q['3'],65536)));
        $timesec = bcdiv($time, 1000);
        $timems = bcmod($time, 1000);

        $q = unpack('@'.($idx + 8)."/N2", $bin);
        $s = pack('V2', $q[2], $q[1]);
        $q = unpack('d', $s);
        $ask = $q[1];

        $q = unpack('@'.($idx + 16)."/N2", $bin);
        $s = pack('V2', $q[2], $q[1]);
        $q = unpack('d', $s);
        $bid = $q[1];

        $q = unpack('@'.($idx + 24)."/N2", $bin);
        $s = pack('V2', $q[2], $q[1]);
        $q = unpack('d', $s);
        $askvol = $q[1];

        $q = unpack('@'.($idx + 32)."/N2", $bin);
        $s = pack('V2', $q[2], $q[1]);
        $q = unpack('d', $s);
        $bidvol = $q[1];

        if ($bid == intval($bid)) {
            $bid = number_format($bid, 1, '.', '');
        }
        if ($ask == intval($ask)) {
            $ask = number_format($ask, 1, '.', '');
        }
        fwrite($outfd, gmstrftime("%Y.%m.%d %H:%M:%S", $timesec).".".str_pad($timems,3,'0',STR_PAD_LEFT).",$bid,$ask,".number_format($bidvol,0,'','').",".number_format($askvol,0,'','')."\n");

        $idx += 40;
    }
}

function decode_ducascopy_bi5($fname, $outfd, $hourtimestamp) {
    print "$fname\n";
    global $iswindows, $extract, $tmpdir, $point;
    if ($iswindows) {
        $cmd = sprintf($extract, $tmpdir, $fname);
        shell_exec($cmd);
        $extracted = $tmpdir.'\\'.substr($fname, strrpos($fname, '/') + 1);
        $extracted = substr($extracted, 0, strrpos($extracted, '.'));
        if (!file_exists($extracted)) {
            echo "Error: failed to extract [$fname]\n";
            exit(1);
        }
        $bin = file_get_contents($extracted);
        unlink($extracted);
    }
    else {
        $cmd = sprintf($extract, $fname);
        $bin = shell_exec($cmd);
    }
    if (strlen($bin) == 0) {
        echo "Error: unable to read extracted file\n";
        exit(1);
    }
    $idx = 0;
    $size = strlen($bin);
    while($idx < $size) {
        //print "$idx $size\n";
        $q = unpack('@'.$idx.'/N', $bin);
        $deltat = $q[1];
        $timesec = $hourtimestamp + $deltat / 1000;
        $timems = $deltat % 1000;


        $q = unpack('@'.($idx + 4)."/N", $bin);
        $ask = $q[1] * $point;
        $q = unpack('@'.($idx + 8)."/N", $bin);
        $bid = $q[1] * $point;
        $q = unpack('@'.($idx + 12)."/C4", $bin);
        $s = pack('C4', $q[4], $q[3], $q[2], $q[1]);
        $q = unpack('f', $s);
        $askvol = $q[1];
        $q = unpack('@'.($idx + 16)."/C4", $bin);
        $s = pack('C4', $q[4], $q[3], $q[2], $q[1]);
        $q = unpack('f', $s);
        $bidvol = $q[1];

        if ($bid == intval($bid)) {
            $bid = number_format($bid, 1, '.', '');
        }
        if ($ask == intval($ask)) {
            $ask = number_format($ask, 1, '.', '');
        }
        fwrite($outfd, gmstrftime("%Y.%m.%d %H:%M:%S", $timesec).".".str_pad($timems,3,'0',STR_PAD_LEFT).",$bid,$ask,".number_format($bidvol,2,'.','').",".number_format($askvol,2,'.','')."\n");
        $idx += 20;
    }
}
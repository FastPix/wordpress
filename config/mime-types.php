<?php
/**
 * FastPix Supported MIME Types and File Extensions
 */

if (!defined('WPINC')) {
    die;
}

return [
    'mime_types' => [
        'video/mp4',
        'video/mpeg',
        'video/ogg',
        'video/quicktime',
        'video/webm',
        'video/3gpp',
        'video/3gpp2',
        'video/x-msvideo',
        'video/x-ms-wmv',
        'video/x-flv',
        'video/x-f4v',
        'video/x-matroska',
        // Lowercase throughout: the browsers compare file.type lowercased against this list verbatim (QA U7).
        'application/x-mpegurl',
        'video/mp2t',
        'video/x-m4v',
        'video/x-ms-asf',
        'video/x-ms-wm',
        'video/x-ms-wmx',
        'video/x-ms-wvx',
        'video/x-sgi-movie',
        'video/divx',
        'video/vnd.rn-realvideo',
        'video/vnd.divx',
        'video/mj2',
        'video/x-dv',
        // Audio — the Add media drop zone takes video and audio.
        'audio/mpeg',
        'audio/mp3',
        'audio/wav',
        'audio/x-wav',
        'audio/wave',
        'audio/aac',
        'audio/x-aac',
        'audio/mp4',
        'audio/x-m4a',
        'audio/m4a',
        'audio/ogg',
        'audio/vorbis',
        'audio/x-matroska',
        'audio/webm',
        'video/x-mts',
        'video/mts',
        'video/m2ts',
        'application/mxf',
        'application/vnd.rn-realmedia',
        'video/x-mts'
    ],
    
    'file_extensions' => [
        'mp4',
        'mov',
        'avi',
        'wmv',
        'flv',
        'f4v',
        'mkv',
        'm4v',
        'mpeg',
        'mpg',
        'ogv',
        'webm',
        '3gp',
        '3g2',
        'asf',
        'wm',
        'wmx',
        'wvx',
        'divx',
        'dv',
        'mp3', 'wav', 'aac', 'm4a', 'ogg', 'rm', 'ts', 'mts', 'm2ts', 'mxf', 'wtv', 'vob'
    ],
    
    'max_file_size' => 50 * 1024 * 1024 * 1024, // 50GB
    
    'chunk_size' => 5 * 1024 * 1024, // 5MB chunks
]; 
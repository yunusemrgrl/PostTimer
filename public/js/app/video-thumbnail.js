/**
 * Video thumbnail generation — client-side via canvas.
 *
 * Called from Curator gallery/listing views via Alpine x-init when a video
 * record has no stored thumbnail (thumbnail_status = 'pending'). Captures
 * the first frame, uploads it to POST /media/{id}/thumbnail, and replaces
 * the film-icon placeholder with the resulting <img>.
 *
 * No server-side FFmpeg dependency. Falls back to the same-origin proxy
 * route (/media/{id}/video) if the direct CDN URL fails CORS.
 */
window.generateVideoThumbnail = function (element, mediaId, videoUrl) {
    if (!mediaId || !videoUrl) return;
    if (element.dataset.thumbnailProcessed) return;
    element.dataset.thumbnailProcessed = '1';

    var proxyUrl = '/media/' + mediaId + '/video';
    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    if (!csrfToken) return;

    function uploadThumbnail(dataUrl) {
        fetch('/media/' + mediaId + '/thumbnail', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken.content,
            },
            body: JSON.stringify({ thumbnail: dataUrl }),
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.thumbnail_url) return;
                var placeholder = element.querySelector('.curator-document-image');
                if (!placeholder) return;
                var img = document.createElement('img');
                img.src = data.thumbnail_url;
                img.loading = 'lazy';
                img.className = 'h-full w-full object-cover';
                placeholder.parentNode.replaceChild(img, placeholder);
            })
            .catch(function () {});
    }

    function captureFrame(url, useCors, isFallback) {
        var video = document.createElement('video');
        if (useCors) video.crossOrigin = 'anonymous';
        video.muted = true;
        video.preload = 'auto';
        video.src = url;

        video.addEventListener('loadeddata', function () {
            try {
                video.currentTime = Math.min(1, (video.duration || 10) * 0.1);
            } catch (e) {
                video.currentTime = 0;
            }
        });

        video.addEventListener('seeked', function () {
            try {
                var canvas = document.createElement('canvas');
                canvas.width = video.videoWidth || 320;
                canvas.height = video.videoHeight || 180;
                canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                canvas.toBlob(function (blob) {
                    if (!blob) {
                        if (!isFallback) captureFrame(proxyUrl, false, true);
                        return;
                    }
                    var reader = new FileReader();
                    reader.onloadend = function () { uploadThumbnail(reader.result); };
                    reader.readAsDataURL(blob);
                }, 'image/jpeg', 0.8);
            } catch (e) {
                if (!isFallback) captureFrame(proxyUrl, false, true);
            }
        });

        video.addEventListener('error', function () {
            if (!isFallback) captureFrame(proxyUrl, false, true);
        });
    }

    captureFrame(videoUrl, true, false);
};

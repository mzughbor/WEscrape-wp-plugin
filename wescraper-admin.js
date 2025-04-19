// JavaScript for async scraping and polling progress
jQuery(document).ready(function($) {
    var pollingInterval = null;
    var scrapeBtn = $('#wescraper-scrape-btn');
    var progressBar = $('#wescraper-progress-bar');
    var progressText = $('#wescraper-progress-text');

    function pollScrapeStatus() {
        $.post(ajaxurl, { action: 'wescraper_check_scrape_status' }, function(response) {
            if (response.status === 'running') {
                // Update progress bar
                var percent = response.progress || 0;
                progressBar.val(percent);
                progressText.text('Scraping: ' + percent + '%');
            } else if (response.status === 'done') {
                clearInterval(pollingInterval);
                progressBar.val(100);
                progressText.text('✅ Scraping complete!');
                scrapeBtn.prop('disabled', false);
            } else if (response.status === 'error') {
                clearInterval(pollingInterval);
                progressText.text('❌ Error: ' + response.message);
                scrapeBtn.prop('disabled', false);
            }
        });
    }

    scrapeBtn.on('click', function(e) {
        e.preventDefault();
        scrapeBtn.prop('disabled', true);
        progressBar.val(0);
        progressText.text('Starting scrape...');
        $.post(ajaxurl, { action: 'wescraper_start_scrape' }, function(response) {
            if (response.status === 'started') {
                pollingInterval = setInterval(pollScrapeStatus, 3000);
            } else {
                progressText.text('❌ Failed to start scrape: ' + response.message);
                scrapeBtn.prop('disabled', false);
            }
        });
    });
});

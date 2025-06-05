document.addEventListener('DOMContentLoaded', () => {
    if (!window.saOttoData) return;
    const { page_url, otto_uuid, context } = window.saOttoData;
    const postPageCrawlLogs = async (pageUrl, uuid, context) => {
        try {
            const saDyoUseragent = navigator.userAgent;
            if (saDyoUseragent.includes('bot')) {
                const saDyoApiUrl = "https://sa.searchatlas.com/api/v2/otto-page-crawl-logs/";
                const saDyoBodyData = {
                    otto_uuid: uuid,
                    url: pageUrl,
                    user_agent: saDyoUseragent,
                    context: context,
                };

                try {
                    const saDyoResources = performance.getEntriesByType('resource');
                    const saDyoTotalResponseTime = saDyoResources.reduce((sum, r) => sum + (r.responseEnd - r.startTime), 0);
                    const saDyoAverageResponseTime = (saDyoResources.length > 0)
                        ? (saDyoTotalResponseTime / saDyoResources.length).toFixed(2)
                        : null;
                    const saDyoTotalDownloadSize = saDyoResources.reduce((sum, r) => sum + (r.transferSize || 0), 0);
                    const saDyoTotalDownloadSizeKB = (saDyoTotalDownloadSize / 1024).toFixed(2);

                    if (saDyoAverageResponseTime) {
                        saDyoBodyData.average_response_time = saDyoAverageResponseTime;
                    }
                    if (saDyoTotalDownloadSizeKB) {
                        saDyoBodyData.total_download_size_kb = saDyoTotalDownloadSizeKB;
                    }
                } catch (error) {

                }

                const saDyoResponse = await fetch(saDyoApiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(saDyoBodyData),
                });

                if (!saDyoResponse.ok) return;
            }
        } catch (error) {

        }
    };

    postPageCrawlLogs(page_url, otto_uuid, context);
});

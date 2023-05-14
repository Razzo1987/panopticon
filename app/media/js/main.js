/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

(() =>
{
    const heartBeatCheck = function ()
    {
        const options = akeeba.System.getOptions("panopticon.heartbeat")
        akeeba.Ajax.ajax(
            options.url,
            {
                type:    "GET",
                cache:   false,
                success: (responseText, statusText, xhr) =>
                         {
                             let response = null;

                             try
                             {
                                 response = JSON.parse(responseText);
                             }
                             catch (e)
                             {
                                 // Maybe the session expired?
                                 return;
                             }

                             const targetId = options.warningId;

                             if (!targetId) {
                                 return;
                             }

                             const elTarget = document.getElementById(targetId);

                             if (!elTarget) {
                                 return;
                             }

                             elTarget.classList.remove('d-none', 'd-block');
                             elTarget.classList.add(response ? 'd-none' : 'd-block');
                         }
            }
        );
    }

    window.addEventListener('DOMContentLoaded', () => {
        // Set up the CRON heartbeat check
        window.setInterval(heartBeatCheck, 30000);

        heartBeatCheck();

        // Enable BS tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]')
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl))
    })
})()

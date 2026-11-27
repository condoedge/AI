if (!window.aiEvalRefreshBound) {
    window.aiEvalRefreshBound = true;

    setInterval(function () {
        var refresh = document.querySelector('.ai-eval-refresh');

        if (refresh && !document.hidden && document.querySelector('[data-ai-eval-live]') && !document.querySelector('.vlModalClose, .vlDrawerClose')) {
            refresh.click();
        }
    }, 15000);
}

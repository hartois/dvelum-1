Ext.onReady(function () {
    app.content.add(
        Ext.create('app.medialibPanel', {
            title: appLang.MODULE_MEDIALIB,
            showType: 'main',
            canEdit: canEdit,
            canDelete: canDelete
        })
    );
});
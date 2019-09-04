Ext.ns('app.cls');

Ext.define('app.cls.projectLoader',{
    extend:'Ext.Base',
    mixins:['Ext.mixin.Observable'],
    loaded:[],
    loadProject:function(controllerUrl,params,callback){
        Ext.Ajax.request({
            url: controllerUrl,
            method: 'post',
            scope:this,
            params:{
                data:Ext.util.JSON.encode(params)
            },
            success: function(response, request) {
                response =  Ext.JSON.decode(response.responseText);
                var me = this;
                if(response.success){
                    if(!Ext.isEmpty(response.data.includes)){
                        me.loadScripts(response.data.includes,function(){
                            callback(response.data);
                        });
                    }

                }else{
                    app.msg(appLang.MESSAGE,response.msg);
                }
            },
            failure:function() {
                Ext.Msg.alert(appLang.MESSAGE, appLang.MSG_LOST_CONNECTION);
            }
        });
    },
    loadScripts:function(list , callback){

        var scriptCount = 0;

        if(!Ext.isEmpty(list.js)){
            scriptCount+= list.js.length;
        }

        if(!Ext.isEmpty(list.css)){
            scriptCount+= list.css.length;
        }

        var me = this;

        Ext.each(list.css, function(item, index){
            if(Ext.Array.contains(me.loaded , item)){
                scriptCount --;
                if(scriptCount==0){
                    callback();
                }
                return;
            }
            Ext.Loader.loadScript({
                url:item,
                onLoad:function(){
                    scriptCount --;
                    me.loaded.push(item);
                    if(scriptCount==0){
                        callback();
                    }
                }
            });
        },me);

        Ext.each(list.js, function(item, index){
            if(Ext.Array.contains(me.loaded , item)){
                scriptCount --;
                if(scriptCount==0){
                    callback();
                }
                return;
            }
            Ext.Loader.loadScript({
                url:item,
                onLoad:function(){
                    scriptCount --;
                    me.loaded.push(item);
                    if(scriptCount==0){
                        callback();
                    }
                }
            });
        },me);
    }
});

app.projectLoader = Ext.create('app.cls.projectLoader');
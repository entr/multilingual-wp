var path = require('path');

module.exports = function(grunt) {
  
  grunt.initConfig({
    jshint: {
      files: ['Gruntfile.js', 'src/**/*.js', 'test/**/*.js'],
      options: {
        globals: {
          jQuery: true
        }
      }
    },
    watch: {
      files: ['<%= jshint.files %>'],
      tasks: ['jshint']
    },
    bower: {
      install: {
        options: {
          cleanTargetDir: false,
          targetDir: '.',
          layout: function(type, component, src) {
            var layout;

            switch ( type ) {
              case 'svg':
                var sub = path.basename( path.dirname(src) );
                layout = path.join( 'flags', sub );
                break;
              case 'css':
              case 'js':
                layout = path.join( type, component );
                break;
              case 'asset':
                layout = path.join( 'css', component );
                break;
              default:
                layout = path.join( 'vendor', component );
            }

            grunt.log.debug( type );
            grunt.log.debug( layout );
            return layout;
          }
        }
      }
    }
  });

  require('jit-grunt')(grunt,{
    'bower': 'grunt-bower-task'
    });

  grunt.registerTask('default', ['jshint']);
};
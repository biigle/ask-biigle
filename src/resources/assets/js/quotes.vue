<script>
import {handleErrorResponse} from './import.js';

export default {
    data() {
        return {
            quote: '',
        };
    },
    methods: {
        refreshQuote() {
            fetch('quotes/new')
                .then((r) => r.text())
                .then(this.handleResponse)
                .catch(handleErrorResponse);
        },
        handleResponse(text) {
            this.quote = text;
        },
    },
    created() {
        this.refreshQuote();
    },
};
</script>
